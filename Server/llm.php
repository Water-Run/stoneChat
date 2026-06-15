<?php
/**
 * stoneChat Server LLM dispatch module.
 *
 * Provider-agnostic chat dispatcher supporting OpenAI and Anthropic
 * protocols. Each provider's api_base defines its own tunnel target,
 * so a single dispatcher can route to multiple vendors. Reuses the
 * ModernNetwork tunnel layer (sc_ensure_tunnel + sc_http_send_raw) for
 * transport, which lets the chat work on PHP 5.2 / Windows XP where
 * TLS 1.2 is not available natively.
 *
 * Public functions (all sc_-prefixed, each wrapped in a function_exists
 * guard so the file can be included multiple times safely):
 *   sc_llm_chat($provider_config, $messages, $system_prompt)
 *       Main entry. Dispatches to OpenAI or Anthropic branch by type.
 *   sc_llm_dispatch($provider_config, $messages, $system_prompt, $stream_callback = null)
 *       Same dispatch but accepts an optional streaming callback.
 *   sc_llm_openai($provider_config, $messages, $system_prompt, $stream_callback = null)
 *       OpenAI-style POST {api_base}/chat/completions with Bearer auth.
 *   sc_llm_anthropic($provider_config, $messages, $system_prompt, $stream_callback = null)
 *       Anthropic-style POST {api_base}/messages with x-api-key + version.
 *   sc_llm_defensive_sse_parse($raw_body)
 *       Parse a possibly-SSE body; tolerates malformed lines, [DONE].
 *   sc_llm_generate_chat_name($provider_config, $messages)
 *       Ask the LLM to summarize a conversation in 2-6 words.
 *
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_llm_resolve_endpoint')) {
    /**
     * Parse a provider's api_base URL into host, port, and path prefix.
     *
     * The path component of api_base is preserved so endpoints are
     * assembled as "<api_base_path>/<endpoint_suffix>". This lets each
     * provider declare its own base prefix (e.g. "/v1") in CONF.ini.
     *
     * @param array $provider_config Provider config (must contain 'api_base').
     * @return array array('host' => string, 'port' => int, 'path_prefix' => string)
     *               or empty array on failure.
     */
    function sc_llm_resolve_endpoint($provider_config) {
        if (!is_array($provider_config)) {
            return array();
        }
        $api_base = isset($provider_config['api_base']) ? (string)$provider_config['api_base'] : '';
        if ($api_base === '') {
            return array();
        }
        $parts = @parse_url($api_base);
        if (!is_array($parts) || empty($parts['host'])) {
            return array();
        }
        return array(
            'host'        => $parts['host'],
            'port'        => isset($parts['port']) ? (int)$parts['port'] : 443,
            'path_prefix' => isset($parts['path']) ? rtrim($parts['path'], '/') : '',
        );
    }
}

if (!class_exists('SC_SseStreamParser')) {
    /**
     * Client-side/Internal SSE parser for raw HTTP body chunks.
     * Buffers lines and decodes data: events.
     */
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
                    if (isset($d['type']) && $d['type'] === 'content_block_delta' && isset($d['delta']['text'])) {
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

if (!function_exists('sc_llm_send_via_tunnel')) {
    /**
     * Send a request through the ModernNetwork tunnel to a per-provider host.
     *
     * Ensures the stunnel tunnel is re-targeted to the provider's host
     * before issuing the request, then delegates to sc_http_send_raw.
     * Returns the same shape as sc_http_request.
     *
     * @param array  $provider_config Per-provider config (needs 'api_base').
     * @param string $method          HTTP method (POST/GET).
     * @param string $path_suffix     Path appended to the api_base's path prefix.
     * @param array  $headers         Extra header lines (no trailing CRLF).
     * @param string $body            Request body ('' or null for GET).
     * @param callback $stream_callback Optional streaming callback.
     * @return array                  sc_http_request-style result.
     */
    function sc_llm_send_via_tunnel($provider_config, $method, $path_suffix, $headers, $body, $stream_callback = null) {
        if (!function_exists('sc_ensure_tunnel')) {
            require_once dirname(__FILE__) . '/../ModernNetwork/proxy.php';
        }
        $ep = sc_llm_resolve_endpoint($provider_config);
        if (empty($ep)) {
            return array('error' => 'invalid_api_base');
        }
        $ini_path   = dirname(__FILE__) . '/../CONF.ini';
        $modern_dir = dirname(__FILE__) . '/../ModernNetwork';
        $cfg = sc_load_modern_config($ini_path);
        if (empty($cfg) || $cfg['stunnel'] === '' || $cfg['ca_cert'] === '') {
            return array('error' => 'config_error');
        }
        $target = array('host' => $ep['host'], 'port' => $ep['port']);
        $full_path = $ep['path_prefix'] . $path_suffix;
        $body_str  = ($body === null) ? '' : (string)$body;
        $hdrs      = is_array($headers) ? $headers : array();

        $is_local = ($target['host'] === 'localhost' || $target['host'] === '127.0.0.1');
        if ($is_local) {
            $resp = sc_http_send_raw(
                $target['port'], $method, $target['host'], $full_path, $hdrs, $body_str, 60, $stream_callback
            );
        } else {
            if (!sc_ensure_tunnel($target, $cfg, $modern_dir)) {
                return array('error' => 'stunnel_start_failed');
            }
            $resp = sc_http_send_raw(
                $cfg['proxy_port'], $method, $target['host'], $full_path, $hdrs, $body_str, 60, $stream_callback
            );
            // Tunnel may have died between ensure and connect; one retry.
            if (!is_array($resp) || (isset($resp['error']) && $resp['error'] === 'connection_failed')) {
                if (sc_ensure_tunnel($target, $cfg, $modern_dir)) {
                    $resp = sc_http_send_raw(
                        $cfg['proxy_port'], $method, $target['host'], $full_path, $hdrs, $body_str, 60, $stream_callback
                    );
                }
            }
        }
        return $resp;
    }
}

if (!function_exists('sc_llm_defensive_sse_parse')) {
    /**
     * Defensive SSE parser.
     *
     * Some providers stream SSE even when the request says stream=false.
     * This parser tolerates partial chunks, mixed line endings, and
     * malformed lines, and honors the [DONE] sentinel.
     *
     * @param string $raw_body Raw response body (possibly SSE).
     * @return array List of events; each is array('event' => string, 'data' => string),
     *               with an extra 'done' => true on the terminating [DONE].
     */
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
                    continue; // Malformed; skip.
                }
                $field = substr($line, 0, $colon);
                $value = substr($line, $colon + 1);
                if (strlen($value) > 0 && $value[0] === ' ') {
                    $value = substr($value, 1); // SSE strips one leading space.
                }
                if ($field === 'event') {
                    $event_name = $value;
                } elseif ($field === 'data') {
                    $data_lines[] = $value;
                }
                // 'id:' and 'retry:' are intentionally ignored.
            }
            if (empty($data_lines)) {
                continue;
            }
            $data = implode("\n", $data_lines);
            if (trim($data) === '[DONE]') {
                $events[] = array('event' => $event_name, 'data' => '', 'done' => true);
                return $events; // Stop at sentinel.
            }
            $events[] = array('event' => $event_name, 'data' => $data);
        }
        return $events;
    }
}

if (!function_exists('sc_llm_build_openai_body')) {
    /**
     * Build the OpenAI chat/completions request body.
     *
     * Prepends the system prompt as a system message; ignores non-array
     * entries in $messages.
     *
     * @param string $model        Model id.
     * @param array  $messages     List of array('role' => ..., 'content' => ...).
     * @param string $system_prompt Optional system prompt.
     * @param bool   $stream       Whether to stream the response.
     * @return array Body array.
     */
    function sc_llm_build_openai_body($model, $messages, $system_prompt, $stream = false) {
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
            'max_tokens' => 1024,
            'stream'     => (bool)$stream,
        );
    }
}

if (!function_exists('sc_llm_build_anthropic_body')) {
    /**
     * Build the Anthropic messages request body.
     *
     * The system prompt is a top-level "system" field (per Anthropic API);
     * it is NOT prepended to messages.
     *
     * @param string $model        Model id.
     * @param array  $messages     List of array('role' => ..., 'content' => ...).
     * @param string $system_prompt Optional system prompt.
     * @param bool   $stream       Whether to stream the response.
     * @return array Body array.
     */
    function sc_llm_build_anthropic_body($model, $messages, $system_prompt, $stream = false) {
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
            'max_tokens' => 1024,
            'stream'     => (bool)$stream,
        );
        if (is_string($system_prompt) && $system_prompt !== '') {
            $body['system'] = $system_prompt;
        }
        return $body;
    }
}

if (!function_exists('sc_llm_parse_openai_response')) {
    /**
     * Parse an OpenAI chat/completions response.
     *
     * Tries SSE first (some OpenAI-compatible endpoints stream anyway),
     * then falls back to plain JSON. Forwards each chunk to the optional
     * streaming callback.
     *
     * @param string $raw_body        Raw body.
     * @param int    $status          HTTP status.
     * @param mixed  $stream_callback Callable or null.
     * @return array                  Result with 'ok', 'status', 'content' or 'error'.
     */
    function sc_llm_parse_openai_response($raw_body, $status, $stream_callback) {
        $cb = is_callable($stream_callback) ? $stream_callback : null;
        // Some OpenAI-compatible endpoints stream SSE even when asked not to.
        if (is_string($raw_body) && strpos($raw_body, 'data:') !== false) {
            $events = sc_llm_defensive_sse_parse($raw_body);
            if (!empty($events)) {
                $content = '';
                foreach ($events as $ev) {
                    if (!empty($ev['done'])) {
                        break;
                    }
                    $d = json_decode($ev['data'], true);
                    if (!is_array($d) || !isset($d['choices'][0]['delta']['content'])) {
                        continue;
                    }
                    $chunk = (string)$d['choices'][0]['delta']['content'];
                    $content .= $chunk;
                    if ($cb !== null) {
                        call_user_func($cb, $chunk, $d);
                    }
                }
                return array('ok' => true, 'status' => $status, 'content' => $content);
            }
        }
        $data = json_decode((string)$raw_body, true);
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'invalid_json_response', 'status' => $status);
        }
        if ($status >= 400) {
            $msg = isset($data['error']['message']) ? (string)$data['error']['message'] : 'http_error';
            return array('ok' => false, 'error' => $msg, 'status' => $status);
        }
        $content = '';
        if (isset($data['choices'][0]['message']['content'])) {
            $content = (string)$data['choices'][0]['message']['content'];
        }
        return array('ok' => true, 'status' => $status, 'content' => $content);
    }
}

if (!function_exists('sc_llm_parse_anthropic_response')) {
    /**
     * Parse an Anthropic messages response.
     *
     * Tries SSE first (Anthropic's streaming format), then plain JSON.
     *
     * @param string $raw_body        Raw body.
     * @param int    $status          HTTP status.
     * @param mixed  $stream_callback Callable or null.
     * @return array                  Result with 'ok', 'status', 'content' or 'error'.
     */
    function sc_llm_parse_anthropic_response($raw_body, $status, $stream_callback) {
        $cb = is_callable($stream_callback) ? $stream_callback : null;
        if (is_string($raw_body) && (strpos($raw_body, 'event:') !== false || strpos($raw_body, 'data:') !== false)) {
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
                    // content_block_delta carries the streaming text chunks.
                    if (isset($d['type']) && $d['type'] === 'content_block_delta'
                        && isset($d['delta']['text'])) {
                        $chunk = (string)$d['delta']['text'];
                        $content .= $chunk;
                        if ($cb !== null) {
                            call_user_func($cb, $chunk, $d);
                        }
                    }
                }
                return array('ok' => true, 'status' => $status, 'content' => $content);
            }
        }
        $data = json_decode((string)$raw_body, true);
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'invalid_json_response', 'status' => $status);
        }
        if ($status >= 400) {
            $msg = isset($data['error']['message']) ? (string)$data['error']['message'] : 'http_error';
            return array('ok' => false, 'error' => $msg, 'status' => $status);
        }
        $content = '';
        if (isset($data['content']) && is_array($data['content'])) {
            $parts = array();
            foreach ($data['content'] as $block) {
                if (is_array($block) && isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $parts[] = (string)$block['text'];
                }
            }
            $content = implode('', $parts);
        }
        return array('ok' => true, 'status' => $status, 'content' => $content);
    }
}

if (!function_exists('sc_llm_openai')) {
    /**
     * Send a chat-completion request to an OpenAI-compatible endpoint.
     *
     * Builds the body, sends it through the tunnel, and parses the
     * response (defensively handling SSE if the provider streams
     * despite stream=false).
     *
     * @param array  $provider_config Per-provider config.
     * @param array  $messages        List of array('role' => ..., 'content' => ...).
     * @param string $system_prompt   Optional system prompt.
     * @param mixed  $stream_callback Optional callable(chunk, event).
     * @return array                  Result.
     */
    function sc_llm_openai($provider_config, $messages, $system_prompt, $stream_callback = null) {
        $api_key = isset($provider_config['api_key']) ? (string)$provider_config['api_key'] : '';
        $model   = isset($provider_config['model'])   ? (string)$provider_config['model']   : '';
        if ($api_key === '' || $model === '') {
            return array('ok' => false, 'error' => 'missing_provider_fields');
        }
        $body = sc_llm_build_openai_body($model, $messages, $system_prompt, $stream_callback !== null);
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
            $provider_config, 'POST', '/chat/completions', $headers, $json, $raw_cb
        );
        if (!is_array($resp) || isset($resp['error'])) {
            $err = isset($resp['error']) ? $resp['error'] : 'unknown';
            return array('ok' => false, 'error' => $err);
        }
        $raw_body = isset($resp['body']) ? $resp['body'] : '';
        $status   = isset($resp['status']) ? (int)$resp['status'] : 0;
        return sc_llm_parse_openai_response($raw_body, $status, $stream_callback);
    }
}

if (!function_exists('sc_llm_anthropic')) {
    /**
     * Send a messages request to an Anthropic-compatible endpoint.
     *
     * @param array  $provider_config Per-provider config.
     * @param array  $messages        List of array('role' => ..., 'content' => ...).
     * @param string $system_prompt   Optional system prompt.
     * @param mixed  $stream_callback Optional callable(chunk, event).
     * @return array                  Result.
     */
    function sc_llm_anthropic($provider_config, $messages, $system_prompt, $stream_callback = null) {
        $api_key = isset($provider_config['api_key']) ? (string)$provider_config['api_key'] : '';
        $model   = isset($provider_config['model'])   ? (string)$provider_config['model']   : '';
        if ($api_key === '' || $model === '') {
            return array('ok' => false, 'error' => 'missing_provider_fields');
        }
        $body = sc_llm_build_anthropic_body($model, $messages, $system_prompt, $stream_callback !== null);
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
            $provider_config, 'POST', '/messages', $headers, $json, $raw_cb
        );
        if (!is_array($resp) || isset($resp['error'])) {
            $err = isset($resp['error']) ? $resp['error'] : 'unknown';
            return array('ok' => false, 'error' => $err);
        }
        $raw_body = isset($resp['body']) ? $resp['body'] : '';
        $status   = isset($resp['status']) ? (int)$resp['status'] : 0;
        return sc_llm_parse_anthropic_response($raw_body, $status, $stream_callback);
    }
}

if (!function_exists('sc_llm_dispatch')) {
    /**
     * Dispatch a chat request to the right provider branch.
     *
     * The provider's 'type' field selects OpenAI vs Anthropic; unknown
     * types return an error.
     *
     * @param array  $provider_config Per-provider config.
     * @param array  $messages        Message list.
     * @param string $system_prompt   Optional system prompt.
     * @param mixed  $stream_callback Optional callable.
     * @return array                  Result.
     */
    function sc_llm_dispatch($provider_config, $messages, $system_prompt, $stream_callback = null) {
        if (!is_array($provider_config)) {
            return array('ok' => false, 'error' => 'invalid_provider');
        }
        $type = isset($provider_config['type'])
            ? strtolower(trim((string)$provider_config['type']))
            : '';
        if ($type === '') {
            return array('ok' => false, 'error' => 'missing_type');
        }
        $sys = is_string($system_prompt) ? $system_prompt : '';
        if ($type === 'openai') {
            return sc_llm_openai($provider_config, $messages, $sys, $stream_callback);
        }
        if ($type === 'anthropic') {
            return sc_llm_anthropic($provider_config, $messages, $sys, $stream_callback);
        }
        return array('ok' => false, 'error' => 'unsupported_type');
    }
}

if (!function_exists('sc_llm_chat')) {
    /**
     * Main entry: send a chat call and return the parsed result.
     *
     * Non-streaming wrapper around sc_llm_dispatch(). For streaming,
     * call sc_llm_dispatch() directly with a callback.
     *
     * @param array  $provider_config Per-provider config.
     * @param array  $messages        Message list.
     * @param string $system_prompt   Optional system prompt.
     * @return array                  Result.
     */
    function sc_llm_chat($provider_config, $messages, $system_prompt) {
        return sc_llm_dispatch($provider_config, $messages, $system_prompt, null);
    }
}

if (!function_exists('sc_llm_generate_chat_name')) {
    /**
     * Ask the LLM itself to summarize a conversation in 2-6 words.
     *
     * Uses the first few messages for context. Returns '' on failure
     * so the caller can fall back to a default name.
     *
     * @param array $provider_config Per-provider config.
     * @param array $messages        Conversation messages.
     * @return string Short title, or '' on failure.
     */
    function sc_llm_generate_chat_name($provider_config, $messages) {
        if (!is_array($provider_config) || !is_array($messages) || empty($messages)) {
            return '';
        }
        $snippet = array();
        $count = 0;
        foreach ($messages as $m) {
            if ($count >= 6) {
                break;
            }
            if (is_array($m) && isset($m['role'], $m['content'])) {
                $snippet[] = (string)$m['role'] . ': ' . (string)$m['content'];
                $count++;
            }
        }
        if (empty($snippet)) {
            return '';
        }
        $prompt = "Summarize the following conversation in 2-6 words as a short title. "
                . "Output ONLY the title, no quotes, no explanation.\n\n"
                . implode("\n", $snippet);
        $result = sc_llm_chat(
            $provider_config,
            array(array('role' => 'user', 'content' => $prompt)),
            ''
        );
        if (!is_array($result) || empty($result['ok'])) {
            return '';
        }
        $name = isset($result['content']) ? trim((string)$result['content']) : '';
        $name = trim($name, " \t\n\r\0\x0B\"'");
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 60);
        } else {
            $name = substr($name, 0, 60);
        }
        return $name;
    }
}
