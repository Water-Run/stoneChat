<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/llm.php
 *
 * Provider-agnostic chat dispatcher supporting OpenAI and Anthropic
 * protocols. Each provider's api_base defines its own tunnel target,
 * so a single dispatcher can route to multiple vendors. Reuses the
 * ModernNetwork tunnel layer (sc_ensure_tunnel + sc_http_send_raw) for
 * transport, which lets the chat work on PHP 5.2 / Windows XP where
 * TLS 1.2 is not available natively.
 *
 * Public helpers (sc_-prefixed, function_exists guarded):
 *   sc_llm_resolve_endpoint($cfg)        host/port/path_prefix
 *   sc_llm_send_via_tunnel(...)          route through stunnel
 *   sc_llm_defensive_sse_parse($body)    SSE parser, tolerant
 *   sc_llm_build_openai_body(...)        build OpenAI request body
 *   sc_llm_build_anthropic_body(...)     build Anthropic request body
 *   sc_llm_parse_openai_response(...)    parse OpenAI reply (SSE|JSON)
 *   sc_llm_parse_anthropic_response(...) parse Anthropic reply
 *   sc_llm_openai(...)                    POST /chat/completions
 *   sc_llm_anthropic(...)                 POST /messages
 *   sc_llm_dispatch(...)                  dispatch by type
 *   sc_llm_chat(...)                      main non-stream entry
 *   sc_llm_generate_chat_name(...)        2-6 word title summary
 *
 * Class:
 *   SC_SseStreamParser                    client-side SSE parser
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_llm_resolve_endpoint($provider_config)
 *   Parse api_base into host / port / path_prefix. The path component
 *   is preserved so endpoints are assembled as
 *   "<api_base_path>/<endpoint_suffix>"; this lets each provider
 *   declare its own base prefix (e.g. "/v1") in CONF.ini. */
if (!function_exists('sc_llm_resolve_endpoint')) {
    function sc_llm_resolve_endpoint($provider_config) {
        if (!is_array($provider_config)) {
            return array();
        }
        $api_base = isset($provider_config['api_base'])
                    ? (string)$provider_config['api_base'] : '';
        if ($api_base === '') {
            return array();
        }
        $parts = @parse_url($api_base);
        if (!is_array($parts) || empty($parts['host'])) {
            return array();
        }
        $scheme = isset($parts['scheme'])
                  ? strtolower((string)$parts['scheme']) : 'https';
        $default_port = ($scheme === 'http') ? 80 : 443;
        return array(
            'scheme'      => $scheme,
            'host'        => $parts['host'],
            'port'        => isset($parts['port']) ? (int)$parts['port']
                                                   : $default_port,
            'path_prefix' => isset($parts['path'])
                             ? rtrim($parts['path'], '/') : '',
        );
    }
}

/* sc_llm_max_tokens($provider_config)
 *   Read a sane max_tokens value from a provider row. */
if (!function_exists('sc_llm_max_tokens')) {
    function sc_llm_max_tokens($provider_config) {
        if (is_array($provider_config) && isset($provider_config['max_tokens'])
            && is_numeric($provider_config['max_tokens'])
            && (int)$provider_config['max_tokens'] > 0) {
            return (int)$provider_config['max_tokens'];
        }
        return 1024;
    }
}

/* sc_llm_request_path($endpoint, $path_suffix)
 *   Build the HTTP path. Normal API bases are prefixes (/v1);
 *   local PHP mock endpoints are already complete files. */
if (!function_exists('sc_llm_request_path')) {
    function sc_llm_request_path($endpoint, $path_suffix) {
        $prefix = '';
        if (is_array($endpoint) && isset($endpoint['path_prefix'])) {
            $prefix = (string)$endpoint['path_prefix'];
        }
        if ($prefix === '') {
            return (string)$path_suffix;
        }
        if (preg_match('/\.php$/i', $prefix)) {
            return $prefix;
        }
        return rtrim($prefix, '/') . '/' . ltrim((string)$path_suffix, '/');
    }
}

/* SC_SseStreamParser
 *   Client-side SSE parser for raw HTTP body chunks. Buffers lines
 *   and decodes data: events. */
if (!class_exists('SC_SseStreamParser')) {
    class SC_SseStreamParser {
        private $buffer = '';
        private $outer_callback;
        private $type;

        public function __construct($outer_callback, $type) {
            $this->outer_callback = $outer_callback;
            $this->type = $type;
        }

        public function feed($chunk) {
            $this->buffer .= $chunk;
            while (true) {
                $pos = strpos($this->buffer, "\n");
                if ($pos === false) {
                    break;
                }
                $line = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                $this->parseLine($line);
            }
        }

        private function parseLine($line) {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            if (strpos($line, 'data:') === 0) {
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    return;
                }
                $d = json_decode($data, true);
                if (!is_array($d)) {
                    return;
                }

                $text = '';
                if ($this->type === 'openai') {
                    if (isset($d['choices'][0]['delta']['content'])) {
                        $text = (string)$d['choices'][0]['delta']['content'];
                    }
                } else if ($this->type === 'anthropic') {
                    if (isset($d['type'])
                        && $d['type'] === 'content_block_delta'
                        && isset($d['delta']['text'])) {
                        $text = (string)$d['delta']['text'];
                    }
                }

                if ($text !== '') {
                    call_user_func($this->outer_callback, $text, $d);
                }
            }
        }
    }
}

/* sc_llm_send_via_tunnel($provider_config, $method, $path_suffix,
 *                        $headers, $body, $stream_callback = null)
 *   Send through the ModernNetwork tunnel to a per-provider host.
 *   Ensures the stunnel tunnel is re-targeted before the request,
 *   then delegates to sc_http_send_raw. */
if (!function_exists('sc_llm_send_via_tunnel')) {
    function sc_llm_send_via_tunnel($provider_config, $method,
                                    $path_suffix, $headers, $body,
                                    $stream_callback = null) {
        if (!function_exists('sc_ensure_tunnel')) {
            require_once dirname(__FILE__) . '/../ModernNetwork/proxy.php';
        }
        $ep = sc_llm_resolve_endpoint($provider_config);
        if (empty($ep)) {
            return array('error' => 'invalid_api_base');
        }
        $target = array('host' => $ep['host'], 'port' => $ep['port']);
        $full_path = sc_llm_request_path($ep, $path_suffix);
        $body_str  = ($body === null) ? '' : (string)$body;
        $hdrs      = is_array($headers) ? $headers : array();

        $scheme = isset($ep['scheme']) ? (string)$ep['scheme'] : 'https';
        $is_local = ($target['host'] === 'localhost'
                     || $target['host'] === '127.0.0.1');
        $is_plain_http = ($scheme === 'http');
        if ($is_plain_http || $is_local) {
            $resp = sc_http_send_raw(
                $target['port'], $method, $target['host'], $full_path,
                $hdrs, $body_str, 60, $stream_callback,
                $is_plain_http ? $target['host'] : '127.0.0.1'
            );
            /* When the target is a localhost mock and the port is
             * closed, distinguish that case from a generic direct-HTTP
             * failure: "mock_unreachable" tells the UI to surface a
             * setup hint (start the mock on 9998). */
            if (!is_array($resp)
                || (isset($resp['error'])
                    && $resp['error'] === 'connection_failed')) {
                if ($is_local) {
                    return array('error' => 'mock_unreachable');
                }
                return array('error' => 'connection_failed');
            }
        } else {
            $ini_path   = dirname(__FILE__) . '/../CONF.ini';
            $modern_dir = dirname(__FILE__) . '/../ModernNetwork';
            $cfg = sc_load_modern_config($ini_path);
            if (empty($cfg) || $cfg['stunnel'] === ''
                || $cfg['ca_cert'] === '') {
                return array('error' => 'config_error');
            }
            if (!sc_ensure_tunnel($target, $cfg, $modern_dir)) {
                return array('error' => 'stunnel_start_failed');
            }
            $resp = sc_http_send_raw(
                $cfg['proxy_port'], $method, $target['host'], $full_path,
                $hdrs, $body_str, 60, $stream_callback
            );
            /* tunnel may have died between ensure and connect; retry. */
            if (!is_array($resp)
                || (isset($resp['error'])
                    && $resp['error'] === 'connection_failed')) {
                if (sc_ensure_tunnel($target, $cfg, $modern_dir)) {
                    $resp = sc_http_send_raw(
                        $cfg['proxy_port'], $method, $target['host'],
                        $full_path, $hdrs, $body_str, 60, $stream_callback
                    );
                }
            }
        }
        return $resp;
    }
}

/* sc_llm_defensive_sse_parse($raw_body)
 *   Some providers stream SSE even when stream=false. Tolerates
 *   partial chunks, mixed line endings, malformed lines, honors
 *   the [DONE] sentinel.
 *   Returns list of array('event'=>..,'data'=>..) with an extra
 *   'done' => true on the terminating [DONE]. */
if (!function_exists('sc_llm_defensive_sse_parse')) {
    function sc_llm_defensive_sse_parse($raw_body) {
        $events = array();
        if (!is_string($raw_body) || $raw_body === '') {
            return $events;
        }
        $normalized = str_replace(array("\r\n", "\r"), "\n", $raw_body);
        $blocks = explode("\n\n", $normalized);
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $event_name = '';
            $data_lines = array();
            $lines = explode("\n", $block);
            foreach ($lines as $line) {
                $colon = strpos($line, ':');
                if ($colon === false) {
                    continue; /* malformed; skip. */
                }
                $field = substr($line, 0, $colon);
                $value = substr($line, $colon + 1);
                if (strlen($value) > 0 && $value[0] === ' ') {
                    $value = substr($value, 1); /* SSE strips one space. */
                }
                if ($field === 'event') {
                    $event_name = $value;
                } elseif ($field === 'data') {
                    $data_lines[] = $value;
                }
                /* 'id:' and 'retry:' are intentionally ignored. */
            }
            if (empty($data_lines)) {
                continue;
            }
            $data = implode("\n", $data_lines);
            if (trim($data) === '[DONE]') {
                $events[] = array('event' => $event_name, 'data' => '',
                                  'done' => true);
                return $events; /* stop at sentinel. */
            }
            $events[] = array('event' => $event_name, 'data' => $data);
        }
        return $events;
    }
}

/* sc_llm_build_openai_body($model, $messages, $system_prompt, $stream,
 *                          $max_tokens)
 *   Build the OpenAI chat/completions request body. Prepends the
 *   system prompt as a system message; ignores non-array entries. */
if (!function_exists('sc_llm_build_openai_body')) {
    function sc_llm_build_openai_body($model, $messages, $system_prompt,
                                      $stream = false, $max_tokens = 1024) {
        $msgs = array();
        if (is_string($system_prompt) && $system_prompt !== '') {
            $msgs[] = array('role' => 'system', 'content' => $system_prompt);
        }
        if (is_array($messages)) {
            foreach ($messages as $m) {
                if (is_array($m) && isset($m['role'], $m['content'])) {
                    $msgs[] = array(
                        'role'    => (string)$m['role'],
                        'content' => (string)$m['content'],
                    );
                }
            }
        }
        return array(
            'model'      => (string)$model,
            'messages'   => $msgs,
            'max_tokens' => ((int)$max_tokens > 0) ? (int)$max_tokens : 1024,
            'stream'     => (bool)$stream,
        );
    }
}

/* sc_llm_build_anthropic_body($model, $messages, $system_prompt, $stream,
 *                             $max_tokens)
 *   Build the Anthropic messages request body. The system prompt
 *   is a top-level "system" field (per Anthropic API); it is NOT
 *   prepended to messages. */
if (!function_exists('sc_llm_build_anthropic_body')) {
    function sc_llm_build_anthropic_body($model, $messages, $system_prompt,
                                         $stream = false,
                                         $max_tokens = 1024) {
        $msgs = array();
        if (is_array($messages)) {
            foreach ($messages as $m) {
                if (is_array($m) && isset($m['role'], $m['content'])) {
                    $msgs[] = array(
                        'role'    => (string)$m['role'],
                        'content' => (string)$m['content'],
                    );
                }
            }
        }
        $body = array(
            'model'      => (string)$model,
            'messages'   => $msgs,
            'max_tokens' => ((int)$max_tokens > 0) ? (int)$max_tokens : 1024,
            'stream'     => (bool)$stream,
        );
        if (is_string($system_prompt) && $system_prompt !== '') {
            $body['system'] = $system_prompt;
        }
        return $body;
    }
}

/* sc_llm_parse_openai_response($raw_body, $status, $stream_callback)
 *   Parse an OpenAI chat/completions response. Tries SSE first
 *   (some OpenAI-compatible endpoints stream anyway), then plain
 *   JSON. Forwards each chunk to the optional stream callback. */
if (!function_exists('sc_llm_parse_openai_response')) {
    function sc_llm_parse_openai_response($raw_body, $status,
                                          $stream_callback) {
        $cb = is_callable($stream_callback) ? $stream_callback : null;
        /* some OpenAI-compatible endpoints stream SSE even when
         * asked not to. */
        if (is_string($raw_body) && strpos($raw_body, 'data:') !== false) {
            $events = sc_llm_defensive_sse_parse($raw_body);
            if (!empty($events)) {
                $content = '';
                foreach ($events as $ev) {
                    if (!empty($ev['done'])) {
                        break;
                    }
                    $d = json_decode($ev['data'], true);
                    if (!is_array($d)
                        || !isset($d['choices'][0]['delta']['content'])) {
                        continue;
                    }
                    $chunk = (string)$d['choices'][0]['delta']['content'];
                    $content .= $chunk;
                    if ($cb !== null) {
                        call_user_func($cb, $chunk, $d);
                    }
                }
                return array('ok' => true, 'status' => $status,
                             'content' => $content);
            }
        }
        $data = json_decode((string)$raw_body, true);
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'invalid_json_response',
                         'status' => $status);
        }
        if ($status >= 400) {
            $msg = isset($data['error']['message'])
                   ? (string)$data['error']['message'] : 'http_error';
            return array('ok' => false, 'error' => $msg, 'status' => $status);
        }
        $content = '';
        if (isset($data['choices'][0]['message']['content'])) {
            $content = (string)$data['choices'][0]['message']['content'];
        }
        return array('ok' => true, 'status' => $status, 'content' => $content);
    }
}

/* sc_llm_parse_anthropic_response($raw_body, $status, $stream_callback)
 *   Parse an Anthropic messages response. Tries SSE first
 *   (Anthropic's streaming format), then plain JSON. */
if (!function_exists('sc_llm_parse_anthropic_response')) {
    function sc_llm_parse_anthropic_response($raw_body, $status,
                                             $stream_callback) {
        $cb = is_callable($stream_callback) ? $stream_callback : null;
        if (is_string($raw_body)
            && (strpos($raw_body, 'event:') !== false
                || strpos($raw_body, 'data:') !== false)) {
            $events = sc_llm_defensive_sse_parse($raw_body);
            if (!empty($events)) {
                $content = '';
                foreach ($events as $ev) {
                    if (!empty($ev['done'])) {
                        break;
                    }
                    $d = json_decode($ev['data'], true);
                    if (!is_array($d)) {
                        continue;
                    }
                    /* content_block_delta carries the streaming chunks. */
                    if (isset($d['type'])
                        && $d['type'] === 'content_block_delta'
                        && isset($d['delta']['text'])) {
                        $chunk = (string)$d['delta']['text'];
                        $content .= $chunk;
                        if ($cb !== null) {
                            call_user_func($cb, $chunk, $d);
                        }
                    }
                }
                return array('ok' => true, 'status' => $status,
                             'content' => $content);
            }
        }
        $data = json_decode((string)$raw_body, true);
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'invalid_json_response',
                         'status' => $status);
        }
        if ($status >= 400) {
            $msg = isset($data['error']['message'])
                   ? (string)$data['error']['message'] : 'http_error';
            return array('ok' => false, 'error' => $msg, 'status' => $status);
        }
        $content = '';
        if (isset($data['content']) && is_array($data['content'])) {
            $parts = array();
            foreach ($data['content'] as $block) {
                if (is_array($block) && isset($block['type'])
                    && $block['type'] === 'text'
                    && isset($block['text'])) {
                    $parts[] = (string)$block['text'];
                }
            }
            $content = implode('', $parts);
        }
        return array('ok' => true, 'status' => $status, 'content' => $content);
    }
}

/* sc_llm_openai($provider_config, $messages, $system_prompt, $stream_cb)
 *   POST {api_base}/chat/completions with Bearer auth. */
if (!function_exists('sc_llm_openai')) {
    function sc_llm_openai($provider_config, $messages, $system_prompt,
                           $stream_callback = null) {
        $api_key = isset($provider_config['api_key'])
                   ? (string)$provider_config['api_key'] : '';
        $model   = isset($provider_config['model'])
                   ? (string)$provider_config['model'] : '';
        if ($api_key === '' || $model === '') {
            return array('ok' => false, 'error' => 'missing_provider_fields');
        }
        $body = sc_llm_build_openai_body($model, $messages, $system_prompt,
                                         $stream_callback !== null,
                                         sc_llm_max_tokens($provider_config));
        if (empty($body['messages'])) {
            return array('ok' => false, 'error' => 'empty_messages');
        }
        $json = json_encode($body);
        if ($json === false) {
            return array('ok' => false, 'error' => 'json_encode_failed');
        }
        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        );
        $raw_cb = null;
        if ($stream_callback !== null) {
            $parser = new SC_SseStreamParser($stream_callback, 'openai');
            $raw_cb = array($parser, 'feed');
        }
        $resp = sc_llm_send_via_tunnel(
            $provider_config, 'POST', '/chat/completions',
            $headers, $json, $raw_cb
        );
        if (!is_array($resp) || isset($resp['error'])) {
            $err = isset($resp['error']) ? $resp['error'] : 'unknown';
            return array('ok' => false, 'error' => $err);
        }
        $raw_body = isset($resp['body']) ? $resp['body'] : '';
        $status   = isset($resp['status']) ? (int)$resp['status'] : 0;
        return sc_llm_parse_openai_response($raw_body, $status, null);
    }
}

/* sc_llm_anthropic($provider_config, $messages, $system_prompt, $stream_cb)
 *   POST {api_base}/messages with x-api-key + version. */
if (!function_exists('sc_llm_anthropic')) {
    function sc_llm_anthropic($provider_config, $messages, $system_prompt,
                              $stream_callback = null) {
        $api_key = isset($provider_config['api_key'])
                   ? (string)$provider_config['api_key'] : '';
        $model   = isset($provider_config['model'])
                   ? (string)$provider_config['model'] : '';
        if ($api_key === '' || $model === '') {
            return array('ok' => false, 'error' => 'missing_provider_fields');
        }
        $body = sc_llm_build_anthropic_body($model, $messages, $system_prompt,
                                            $stream_callback !== null,
                                            sc_llm_max_tokens($provider_config));
        if (empty($body['messages'])) {
            return array('ok' => false, 'error' => 'empty_messages');
        }
        $json = json_encode($body);
        if ($json === false) {
            return array('ok' => false, 'error' => 'json_encode_failed');
        }
        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        );
        $raw_cb = null;
        if ($stream_callback !== null) {
            $parser = new SC_SseStreamParser($stream_callback, 'anthropic');
            $raw_cb = array($parser, 'feed');
        }
        $resp = sc_llm_send_via_tunnel(
            $provider_config, 'POST', '/messages',
            $headers, $json, $raw_cb
        );
        if (!is_array($resp) || isset($resp['error'])) {
            $err = isset($resp['error']) ? $resp['error'] : 'unknown';
            return array('ok' => false, 'error' => $err);
        }
        $raw_body = isset($resp['body']) ? $resp['body'] : '';
        $status   = isset($resp['status']) ? (int)$resp['status'] : 0;
        return sc_llm_parse_anthropic_response($raw_body, $status, null);
    }
}

/* sc_llm_dispatch($provider_config, $messages, $system_prompt, $stream_cb)
 *   Dispatch a chat request to the right provider branch by 'type'. */
if (!function_exists('sc_llm_dispatch')) {
    function sc_llm_dispatch($provider_config, $messages, $system_prompt,
                             $stream_callback = null) {
        if (!is_array($provider_config)) {
            return array('ok' => false, 'error' => 'invalid_provider');
        }
        $type = isset($provider_config['type'])
                ? strtolower(trim((string)$provider_config['type'])) : '';
        if ($type === '') {
            return array('ok' => false, 'error' => 'missing_type');
        }
        $sys = is_string($system_prompt) ? $system_prompt : '';
        if ($type === 'openai') {
            return sc_llm_openai($provider_config, $messages, $sys,
                                 $stream_callback);
        }
        if ($type === 'anthropic') {
            return sc_llm_anthropic($provider_config, $messages, $sys,
                                    $stream_callback);
        }
        return array('ok' => false, 'error' => 'unsupported_type');
    }
}

/* sc_llm_chat($provider_config, $messages, $system_prompt)
 *   Non-streaming wrapper around sc_llm_dispatch(). */
if (!function_exists('sc_llm_chat')) {
    function sc_llm_chat($provider_config, $messages, $system_prompt) {
        return sc_llm_dispatch($provider_config, $messages, $system_prompt,
                               null);
    }
}

/* sc_llm_generate_chat_name($provider_config, $messages)
 *   Ask the LLM to summarise a conversation in 2-6 words. Uses
 *   the first few messages; returns '' on failure. */
if (!function_exists('sc_llm_generate_chat_name')) {
    function sc_llm_generate_chat_name($provider_config, $messages) {
        if (!is_array($provider_config) || !is_array($messages)
            || empty($messages)) {
            return '';
        }
        $snippet = array();
        $count = 0;
        foreach ($messages as $m) {
            if ($count >= 6) {
                break;
            }
            if (is_array($m) && isset($m['role'], $m['content'])) {
                $snippet[] = (string)$m['role'] . ': '
                           . (string)$m['content'];
                $count++;
            }
        }
        if (empty($snippet)) {
            return '';
        }
        $prompt = "Summarize the following conversation in 2-6 words "
                . "as a short title. Output ONLY the title, no quotes, "
                . "no explanation.\n\n" . implode("\n", $snippet);
        $result = sc_llm_chat(
            $provider_config,
            array(array('role' => 'user', 'content' => $prompt)),
            ''
        );
        if (!is_array($result) || empty($result['ok'])) {
            return '';
        }
        $name = isset($result['content'])
                ? trim((string)$result['content']) : '';
        $name = trim($name, " \t\n\r\0\x0B\"'");
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 60);
        } else {
            $name = substr($name, 0, 60);
        }
        return $name;
    }
}
