<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/api/chat.php
 *
 * Single-file JSON API for chat operations. Routing is body-driven
 * via the "action" field. Every request must be POST and carry a
 * valid session cookie (validated against cfg[auth][password] in
 * constant time).
 *
 * Wire format (request body, all keys action-specific):
 *
 *   POST action="send"           { "chat_id": "<id>",
 *                                  "message": "<user text>",
 *                                  "provider_id": "<optional override>" }
 *       - load chat history (sc_history_load_messages)
 *       - persist the new user message
 *       - dispatch to sc_llm_chat() with provider resolved from
 *         explicit provider_id, then chat meta, then first provider
 *       - persist the assistant reply (even on partial failure of
 *         the rest of the flow)
 *       - on the first turn, ask the LLM for a short chat name
 *         and write it to meta.txt via sc_history_rename()
 *       response: { "ok": true,  "assistant": "<text>",
 *                   "chat_name": "<string, possibly empty>" }
 *                or { "ok": false, "error": "<code>" }
 *
 *   POST action="connect_check"  { "provider_id": "<id>" }
 *       - ping the provider with a fixed "ping" message via
 *         sc_llm_chat(); record wall-clock latency
 *       response: { "ok": true|false, "latency_ms": <int>,
 *                   "error": "<code or empty>" }
 *
 *   POST action="regenerate"     { "chat_id": "<id>" }
 *       - delete the highest-indexed assistant-NNN.txt in the chat
 *       - re-dispatch sc_llm_chat() with the remaining history
 *       - persist the new assistant reply
 *       response: { "ok": true,  "assistant": "<text>" }
 *                or { "ok": false, "error": "<code>" }
 *
 * Common error codes: auth_required (401), method_not_allowed (405),
 * bad_chat_id, chat_not_found, empty_message, provider_not_found,
 * llm_unavailable, no_result, unknown, timeout[:<cause>],
 * unknown_action.
 *
 * The password (or any derivative) is never echoed in a response.
 * Provider secrets are never part of any response payload.
 *
 * PHP 5.2 compatible (no closures, no [] array syntax, no
 * json_last_error, no http_response_code, function_exists guards
 * on every helper).
 * ------------------------------------------------------------------------- */

/* ---- module includes -------------------------------------------- */
require_once dirname(__FILE__) . '/../boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../auth.php';
require_once dirname(__FILE__) . '/../llm.php';
require_once dirname(__FILE__) . '/../history.php';
require_once dirname(__FILE__) . '/../i18n.php';

/* ---- generic HTTP / body helpers -------------------------------- */

/* sc_api_chat_emit($status, $payload)
 *   Send a JSON response with the given HTTP status and exit. */
if (!function_exists('sc_api_chat_emit')) {
    function sc_api_chat_emit($status, $payload) {
        if (!headers_sent()) {
            $protocol = 'HTTP/1.0';
            if (isset($_SERVER['SERVER_PROTOCOL'])
                && is_string($_SERVER['SERVER_PROTOCOL'])
                && $_SERVER['SERVER_PROTOCOL'] !== '') {
                $protocol = $_SERVER['SERVER_PROTOCOL'];
            }
            $reason = sc_api_chat_status_reason((int)$status);
            if ($reason !== '') {
                header($protocol . ' ' . (int)$status . ' ' . $reason);
            } else {
                header($protocol . ' ' . (int)$status);
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
        $body = json_encode($payload);
        if (!is_string($body)) {
            $body = '{"ok":false,"error":"json_encode_failed"}';
        }
        echo $body;
        exit;
    }
}

/* sc_api_chat_status_reason($status)
 *   Map numeric HTTP status to its canonical reason phrase. */
if (!function_exists('sc_api_chat_status_reason')) {
    function sc_api_chat_status_reason($status) {
        $reasons = array(
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
        );
        $code = (int)$status;
        return isset($reasons[$code]) ? $reasons[$code] : '';
    }
}

/* sc_api_chat_load_cfg()
 *   Load CONF.ini from the project root, or return an empty array. */
if (!function_exists('sc_api_chat_load_cfg')) {
    function sc_api_chat_load_cfg() {
        $ini = dirname(__FILE__) . DIRECTORY_SEPARATOR
             . '..' . DIRECTORY_SEPARATOR . '..'
             . DIRECTORY_SEPARATOR . 'CONF.ini';
        if (is_file($ini) && is_readable($ini)
            && function_exists('sc_load_config')) {
            $cfg = sc_load_config($ini);
            if (is_array($cfg)) {
                return $cfg;
            }
        }
        return array();
    }
}

/* sc_api_chat_is_authorized($cfg)
 *   Check that the request carries a valid session cookie. */
if (!function_exists('sc_api_chat_is_authorized')) {
    function sc_api_chat_is_authorized($cfg) {
        if (!is_array($cfg)) {
            return false;
        }
        $name = 'sc_auth';
        if (isset($cfg['auth']['cookie_name'])
            && (string)$cfg['auth']['cookie_name'] !== '') {
            $name = (string)$cfg['auth']['cookie_name'];
        }
        $token = '';
        if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
            $token = $_COOKIE[$name];
        }
        if ($token === '' && isset($_COOKIE['sc_session'])
            && is_string($_COOKIE['sc_session'])) {
            $token = $_COOKIE['sc_session'];
        }
        if ($token === '') {
            return false;
        }
        if (function_exists('sc_auth_verify_token')) {
            return sc_auth_verify_token($token, $cfg);
        }
        if (strlen($token) < 6 || strpos($token, 'scv1:') !== 0) {
            return false;
        }
        return true;
    }
}

/* sc_api_chat_read_body()
 *   Read the request body, accepting both JSON and form-encoded. */
if (!function_exists('sc_api_chat_read_body')) {
    function sc_api_chat_read_body() {
        $raw = '';
        if (isset($_SERVER['REQUEST_METHOD'])
            && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            $raw = @file_get_contents('php://input');
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (isset($_POST) && is_array($_POST) && !empty($_POST)) {
            return $_POST;
        }
        return array();
    }
}

/* sc_api_chat_str($src, $key, $default)
 *   Fetch a string field from an assoc array, with fallback. */
if (!function_exists('sc_api_chat_str')) {
    function sc_api_chat_str($src, $key, $default) {
        if (!is_array($src) || !isset($src[$key])) {
            return (string)$default;
        }
        $v = $src[$key];
        if (!is_string($v) && !is_numeric($v)) {
            return (string)$default;
        }
        return (string)$v;
    }
}

/* sc_api_chat_action($body)
 *   Resolve the action name from the request body, lowercased. */
if (!function_exists('sc_api_chat_action')) {
    function sc_api_chat_action($body) {
        if (!is_array($body)) {
            return '';
        }
        $a = '';
        if (isset($body['action']) && is_string($body['action'])) {
            $a = $body['action'];
        }
        return strtolower(trim($a));
    }
}

/* sc_api_chat_chat_id($body)
 *   Read a chat id from "chat_id", "id", or "conversation_id". */
if (!function_exists('sc_api_chat_chat_id')) {
    function sc_api_chat_chat_id($body) {
        if (!is_array($body)) {
            return '';
        }
        if (isset($body['chat_id']) && is_string($body['chat_id'])) {
            return $body['chat_id'];
        }
        if (isset($body['conversation_id'])
            && is_string($body['conversation_id'])) {
            return $body['conversation_id'];
        }
        if (isset($body['id'])
            && (is_string($body['id']) || is_numeric($body['id']))) {
            return (string)$body['id'];
        }
        return '';
    }
}

/* ---- provider / history helpers -------------------------------- */

/* sc_api_chat_ini_path()
 *   Absolute path to CONF.ini (project root, two levels up). */
if (!function_exists('sc_api_chat_ini_path')) {
    function sc_api_chat_ini_path() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR
             . '..' . DIRECTORY_SEPARATOR
             . '..' . DIRECTORY_SEPARATOR
             . 'CONF.ini';
    }
}

/* sc_api_chat_load_providers()
 *   Load the configured providers from CONF.ini as raw rows. */
if (!function_exists('sc_api_chat_load_providers')) {
    function sc_api_chat_load_providers() {
        if (!function_exists('sc_load_providers')) {
            return array();
        }
        $raw = sc_load_providers(sc_api_chat_ini_path());
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $p) {
            if (is_array($p)) {
                $out[] = $p;
            }
        }
        return $out;
    }
}

/* sc_api_chat_find_provider($providers, $provider_id)
 *   Look up a provider row by its id. */
if (!function_exists('sc_api_chat_find_provider')) {
    function sc_api_chat_find_provider($providers, $provider_id) {
        if (!is_array($providers) || !is_string($provider_id)
            || $provider_id === '') {
            return null;
        }
        foreach ($providers as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = isset($p['id']) ? (string)$p['id'] : '';
            if ($id === $provider_id) {
                return $p;
            }
        }
        return null;
    }
}

/* sc_api_chat_provider_timeout($provider)
 *   Read the timeout (seconds) for a provider, default 60. */
if (!function_exists('sc_api_chat_provider_timeout')) {
    function sc_api_chat_provider_timeout($provider) {
        if (is_array($provider) && isset($provider['timeout'])
            && is_numeric($provider['timeout'])
            && (int)$provider['timeout'] > 0) {
            return (int)$provider['timeout'];
        }
        return 60;
    }
}

/* sc_api_chat_provider_stream($provider)
 *   Whether this provider should request upstream SSE streaming. */
if (!function_exists('sc_api_chat_provider_stream')) {
    function sc_api_chat_provider_stream($provider) {
        if (!is_array($provider) || !isset($provider['stream'])) {
            return false;
        }
        $text = strtolower(trim((string)$provider['stream']));
        if ($text === '1' || $text === 'true' || $text === 'yes'
            || $text === 'on') {
            return true;
        }
        return false;
    }
}

/* sc_api_chat_resolve_provider($providers, $cfg, $chat_id, $explicit)
 *   Resolve the provider row: explicit > chat meta > first configured. */
if (!function_exists('sc_api_chat_resolve_provider')) {
    function sc_api_chat_resolve_provider($providers, $cfg, $chat_id,
                                           $explicit_id) {
        if (is_string($explicit_id) && $explicit_id !== '') {
            $p = sc_api_chat_find_provider($providers, $explicit_id);
            if ($p !== null) {
                return $p;
            }
        }
        if (is_string($chat_id) && $chat_id !== ''
            && function_exists('sc_history_load_meta')) {
            $meta = sc_history_load_meta($chat_id);
            if (is_array($meta) && isset($meta['provider_id'])
                && is_string($meta['provider_id'])
                && $meta['provider_id'] !== '') {
                $p = sc_api_chat_find_provider($providers,
                                                $meta['provider_id']);
                if ($p !== null) {
                    return $p;
                }
            }
        }
        if (is_array($providers) && !empty($providers)) {
            return $providers[0];
        }
        return null;
    }
}

/* sc_api_chat_load_system_prompt($chat_id)
 *   Read the system prompt (system.txt) for a chat. */
if (!function_exists('sc_api_chat_load_system_prompt')) {
    function sc_api_chat_load_system_prompt($chat_id) {
        if (!is_string($chat_id) || $chat_id === '') {
            return '';
        }
        if (!function_exists('sc_history_chat_dir')) {
            return '';
        }
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '' || !is_dir($dir)) {
            return '';
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'system.txt';
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $text = @file_get_contents($path);
        if (!is_string($text)) {
            return '';
        }
        return $text;
    }
}

/* sc_api_chat_messages_to_llm($messages)
 *   Convert history messages to the shape sc_llm_chat() expects.
 *   sc_history_load_messages returns ('role'=>..,'text'=>..);
 *   sc_llm_chat expects ('role'=>..,'content'=>..). Only user /
 *   assistant roles pass through. */
if (!function_exists('sc_api_chat_messages_to_llm')) {
    function sc_api_chat_messages_to_llm($messages) {
        $out = array();
        if (!is_array($messages)) {
            return $out;
        }
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = isset($m['role']) ? (string)$m['role'] : '';
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $content = '';
            if (isset($m['text'])) {
                $content = (string)$m['text'];
            } elseif (isset($m['content'])) {
                $content = (string)$m['content'];
            }
            $out[] = array('role' => $role, 'content' => $content);
        }
        return $out;
    }
}

/* sc_api_chat_delete_last_assistant($chat_id)
 *   Delete the highest-indexed assistant-NNN.txt in a chat. */
if (!function_exists('sc_api_chat_delete_last_assistant')) {
    function sc_api_chat_delete_last_assistant($chat_id) {
        if (!function_exists('sc_history_chat_dir')) {
            return false;
        }
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        $dh = @opendir($dir);
        if ($dh === false) {
            return false;
        }
        $max = 0;
        while (($name = @readdir($dh)) !== false) {
            if (!preg_match('/^assistant-(\d{3})\.txt$/', $name, $m)) {
                continue;
            }
            $idx = (int)$m[1];
            if ($idx < 1 || $idx > 999) {
                continue;
            }
            if ($idx > $max) {
                $max = $idx;
            }
        }
        @closedir($dh);
        if ($max < 1) {
            return false;
        }
        $fname = 'assistant-' . sprintf('%03d', $max) . '.txt';
        $path = $dir . DIRECTORY_SEPARATOR . $fname;
        if (!is_file($path)) {
            return false;
        }
        return @unlink($path);
    }
}

/* sc_api_chat_check_timeout($elapsed_ms, $timeout_seconds, $result)
 *   Decide whether a completed call exceeded the provider timeout.
 *   Returns false when within budget; an error code ("timeout" or
 *   "timeout:...") when over. */
if (!function_exists('sc_api_chat_check_timeout')) {
    function sc_api_chat_check_timeout($elapsed_ms, $timeout_seconds,
                                       $result) {
        if ($timeout_seconds <= 0) {
            return false;
        }
        if ($elapsed_ms <= ($timeout_seconds * 1000)) {
            return false;
        }
        $err = 'timeout';
        if (is_array($result) && isset($result['error'])
            && (string)$result['error'] !== '') {
            $err = 'timeout:' . (string)$result['error'];
        }
        return $err;
    }
}

/* ---- action handlers -------------------------------------------- */

/* sc_api_chat_handle_send($cfg, $body)
 *   "send": persist a user turn, dispatch to LLM, persist the
 *   assistant turn, optionally auto-name the chat.
 *
 *   Order of operations is deliberate:
 *     1. Save the user message FIRST so even a hard crash leaves a
 *        recoverable history (the user's words are not lost).
 *     2. Dispatch to sc_llm_chat().
 *     3. Save the assistant message. If the LLM call failed, the
 *        history still records the user turn intact.
 *     4. On the first turn, ask the LLM for a short title. */
if (!function_exists('sc_api_chat_handle_send')) {
    function sc_api_chat_handle_send($cfg, $body) {
        $chat_id = sc_api_chat_chat_id($body);
        $message = sc_api_chat_str($body, 'message', '');
        if ($message === '') {
            $message = sc_api_chat_str($body, 'content', '');
        }
        $explicit = sc_api_chat_str($body, 'provider_id', '');

        if (!function_exists('sc_history_chat_dir')
            || sc_history_chat_dir($chat_id) === '') {
            return array('ok' => false, 'error' => 'bad_chat_id');
        }
        $dir = sc_history_chat_dir($chat_id);
        if (!is_dir($dir)) {
            return array('ok' => false, 'error' => 'chat_not_found');
        }
        if ($message === '') {
            return array('ok' => false, 'error' => 'empty_message');
        }
        if (!function_exists('sc_llm_chat')) {
            return array('ok' => false, 'error' => 'llm_unavailable');
        }

        /* resolve provider (explicit > meta > first configured). */
        $providers = sc_api_chat_load_providers();
        $provider  = sc_api_chat_resolve_provider(
            $providers, $cfg, $chat_id, $explicit
        );
        if ($provider === null) {
            return array('ok' => false, 'error' => 'provider_not_found');
        }
        $timeout = sc_api_chat_provider_timeout($provider);

        /* load history BEFORE saving the new user message so we can
         * detect "first turn" reliably. */
        $hist = array();
        if (function_exists('sc_history_load_messages')) {
            $hist = sc_history_load_messages($chat_id);
        }
        $is_first_turn = empty($hist);

        /* 1. persist the user message (defensive: even an LLM crash
         *    afterwards leaves the user input on disk). */
        if (function_exists('sc_history_append_message')) {
            sc_history_append_message($chat_id, 'user', $message);
        }

        /* 2. build the LLM request and dispatch. */
        $llm_msgs  = sc_api_chat_messages_to_llm($hist);
        $llm_msgs[] = array('role' => 'user', 'content' => $message);
        $system    = sc_api_chat_load_system_prompt($chat_id);
        $start     = microtime(true);
        $result    = sc_llm_chat($provider, $llm_msgs, $system);
        $elapsed   = (microtime(true) - $start) * 1000;

        /* 3. save the assistant message (independent of outcome). */
        $assistant = '';
        $chat_name = '';
        $ok_payload = false;
        if (is_array($result)) {
            $timeout_err = sc_api_chat_check_timeout(
                $elapsed, $timeout, $result
            );
            if ($timeout_err !== false) {
                $err = $timeout_err;
            } elseif (empty($result['ok'])) {
                $err = isset($result['error'])
                    ? (string)$result['error'] : 'unknown';
            } else {
                $assistant = isset($result['content'])
                    ? (string)$result['content'] : '';
                $err = '';
                $ok_payload = true;
            }
        } else {
            $err = 'no_result';
        }
        if ($ok_payload && $assistant !== ''
            && function_exists('sc_history_append_message')) {
            sc_history_append_message($chat_id, 'assistant', $assistant);
        }
        if (!$ok_payload) {
            return array('ok' => false, 'error' => $err);
        }

        /* 4. first-turn auto-naming. */
        if ($is_first_turn
            && function_exists('sc_llm_generate_chat_name')
            && function_exists('sc_history_rename')) {
            $name_seed = array(
                array('role' => 'user',      'text' => $message),
                array('role' => 'assistant', 'text' => $assistant),
            );
            $name = sc_llm_generate_chat_name($provider, $name_seed);
            if (is_string($name) && $name !== '') {
                sc_history_rename($chat_id, $name);
                $chat_name = $name;
            }
        }
        return array(
            'ok'        => true,
            'assistant' => $assistant,
            'chat_name' => $chat_name,
        );
    }
}

/* sc_api_chat_handle_connect_check($cfg, $body)
 *   "connect_check": ping a provider with a fixed prompt. Records
 *   wall-clock latency. */
if (!function_exists('sc_api_chat_handle_connect_check')) {
    function sc_api_chat_handle_connect_check($cfg, $body) {
        $provider_id = sc_api_chat_str($body, 'provider_id', '');
        if ($provider_id === '') {
            return array('ok' => false, 'latency_ms' => 0,
                         'error' => 'no_provider_id');
        }
        $providers = sc_api_chat_load_providers();
        $provider  = sc_api_chat_find_provider($providers, $provider_id);
        if ($provider === null) {
            return array('ok' => false, 'latency_ms' => 0,
                         'error' => 'provider_not_found');
        }
        if (!function_exists('sc_llm_chat')) {
            return array('ok' => false, 'latency_ms' => 0,
                         'error' => 'llm_unavailable');
        }
        $timeout = sc_api_chat_provider_timeout($provider);
        $messages = array(array('role' => 'user', 'content' => 'ping'));
        $start = microtime(true);
        $result = sc_llm_chat($provider, $messages, '');
        $elapsed_ms = (int)round((microtime(true) - $start) * 1000);

        $timeout_err = sc_api_chat_check_timeout(
            $elapsed_ms, $timeout, $result
        );
        if ($timeout_err !== false) {
            return array(
                'ok'         => false,
                'latency_ms' => $elapsed_ms,
                'error'      => $timeout_err,
            );
        }
        if (!is_array($result)) {
            return array(
                'ok'         => false,
                'latency_ms' => $elapsed_ms,
                'error'      => 'no_result',
            );
        }
        if (empty($result['ok'])) {
            $err = isset($result['error'])
                ? (string)$result['error'] : 'unknown';
            return array(
                'ok'         => false,
                'latency_ms' => $elapsed_ms,
                'error'      => $err,
            );
        }
        return array(
            'ok'         => true,
            'latency_ms' => $elapsed_ms,
            'error'      => '',
        );
    }
}

/* sc_api_chat_handle_regenerate($cfg, $body)
 *   "regenerate": re-run the LLM for the most recent user turn. */
if (!function_exists('sc_api_chat_handle_regenerate')) {
    function sc_api_chat_handle_regenerate($cfg, $body) {
        $chat_id = sc_api_chat_chat_id($body);
        if (!function_exists('sc_history_chat_dir')
            || sc_history_chat_dir($chat_id) === '') {
            return array('ok' => false, 'error' => 'bad_chat_id');
        }
        $dir = sc_history_chat_dir($chat_id);
        if (!is_dir($dir)) {
            return array('ok' => false, 'error' => 'chat_not_found');
        }
        if (!function_exists('sc_llm_chat')) {
            return array('ok' => false, 'error' => 'llm_unavailable');
        }

        /* drop the last assistant message; reload history;
         * resolve provider; dispatch; persist. */
        sc_api_chat_delete_last_assistant($chat_id);
        $hist = array();
        if (function_exists('sc_history_load_messages')) {
            $hist = sc_history_load_messages($chat_id);
        }
        $providers = sc_api_chat_load_providers();
        $provider  = sc_api_chat_resolve_provider(
            $providers, $cfg, $chat_id, ''
        );
        if ($provider === null) {
            return array('ok' => false, 'error' => 'provider_not_found');
        }
        $timeout  = sc_api_chat_provider_timeout($provider);
        $llm_msgs = sc_api_chat_messages_to_llm($hist);
        $system   = sc_api_chat_load_system_prompt($chat_id);

        $start  = microtime(true);
        $result = sc_llm_chat($provider, $llm_msgs, $system);
        $elapsed = (microtime(true) - $start) * 1000;

        if (!is_array($result)) {
            return array('ok' => false, 'error' => 'no_result');
        }
        $timeout_err = sc_api_chat_check_timeout(
            $elapsed, $timeout, $result
        );
        if ($timeout_err !== false) {
            return array('ok' => false, 'error' => $timeout_err);
        }
        if (empty($result['ok'])) {
            $err = isset($result['error'])
                ? (string)$result['error'] : 'unknown';
            return array('ok' => false, 'error' => $err);
        }
        $assistant = isset($result['content'])
            ? (string)$result['content'] : '';
        if ($assistant !== ''
            && function_exists('sc_history_append_message')) {
            sc_history_append_message($chat_id, 'assistant', $assistant);
        }
        return array(
            'ok'        => true,
            'assistant' => $assistant,
        );
    }
}

/* sc_api_chat_stream_result_error($result)
 *   Map a streaming dispatch result to an SSE error code. Empty
 *   string means "no dispatch-level error". */
if (!function_exists('sc_api_chat_stream_result_error')) {
    function sc_api_chat_stream_result_error($result) {
        if (!is_array($result)) {
            return 'no_result';
        }
        if (!empty($result['ok'])) {
            return '';
        }
        if (isset($result['error']) && (string)$result['error'] !== '') {
            return (string)$result['error'];
        }
        return 'unknown';
    }
}

/* sc_api_chat_handle_send_stream($cfg, $body)
 *   "send" action in streaming mode. */
if (!function_exists('sc_api_chat_handle_send_stream')) {
    function sc_api_chat_handle_send_stream($cfg, $body) {
        $chat_id = sc_api_chat_chat_id($body);
        $message = sc_api_chat_str($body, 'message', '');
        if ($message === '') {
            $message = sc_api_chat_str($body, 'content', '');
        }
        $explicit = sc_api_chat_str($body, 'provider_id', '');

        if (!function_exists('sc_history_chat_dir')
            || sc_history_chat_dir($chat_id) === '') {
            echo "data: " . json_encode(array('error' => 'bad_chat_id'))
               . "\n\n";
            exit;
        }
        $dir = sc_history_chat_dir($chat_id);
        if (!is_dir($dir)) {
            echo "data: " . json_encode(array('error' => 'chat_not_found'))
               . "\n\n";
            exit;
        }
        if ($message === '') {
            echo "data: " . json_encode(array('error' => 'empty_message'))
               . "\n\n";
            exit;
        }
        if (!function_exists('sc_llm_dispatch')) {
            echo "data: " . json_encode(array('error' => 'llm_unavailable'))
               . "\n\n";
            exit;
        }

        $providers = sc_api_chat_load_providers();
        $provider  = sc_api_chat_resolve_provider(
            $providers, $cfg, $chat_id, $explicit
        );
        if ($provider === null) {
            echo "data: " . json_encode(
                array('error' => 'provider_not_found')) . "\n\n";
            exit;
        }
        $timeout = sc_api_chat_provider_timeout($provider);

        $hist = array();
        if (function_exists('sc_history_load_messages')) {
            $hist = sc_history_load_messages($chat_id);
        }
        $is_first_turn = empty($hist);

        if (function_exists('sc_history_append_message')) {
            sc_history_append_message($chat_id, 'user', $message);
        }

        $llm_msgs   = sc_api_chat_messages_to_llm($hist);
        $llm_msgs[] = array('role' => 'user', 'content' => $message);
        $system     = sc_api_chat_load_system_prompt($chat_id);

        if (!class_exists('SC_StreamAccumulator')) {
            class SC_StreamAccumulator {
                public static $content = '';
                public static function callback($chunk, $event) {
                    self::$content .= $chunk;
                    echo "data: " . json_encode(
                        array('content' => $chunk)) . "\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            }
        }

        SC_StreamAccumulator::$content = '';

        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }
        while (ob_get_level()) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        if (sc_api_chat_provider_stream($provider)) {
            $result = sc_llm_dispatch(
                $provider, $llm_msgs, $system,
                array('SC_StreamAccumulator', 'callback')
            );
            $assistant = SC_StreamAccumulator::$content;
        } else {
            $start  = microtime(true);
            $result = sc_llm_dispatch($provider, $llm_msgs, $system, null);
            $elapsed = (microtime(true) - $start) * 1000;
            $timeout_err = sc_api_chat_check_timeout(
                $elapsed, $timeout, $result
            );
            if ($timeout_err !== false) {
                $result = array('ok' => false, 'error' => $timeout_err);
            }
            $assistant = '';
            if (is_array($result) && !empty($result['ok'])
                && isset($result['content'])) {
                $assistant = (string)$result['content'];
            }
            if ($assistant !== '') {
                echo "data: " . json_encode(
                    array('content' => $assistant)) . "\n\n";
                flush();
            }
        }
        $stream_err = sc_api_chat_stream_result_error($result);
        if ($assistant === '' && $stream_err !== '') {
            echo "data: " . json_encode(array('error' => $stream_err))
               . "\n\n";
            exit;
        }
        if ($assistant !== ''
            && function_exists('sc_history_append_message')) {
            sc_history_append_message($chat_id, 'assistant', $assistant);
        }

        $chat_name = '';
        if ($is_first_turn && $assistant !== ''
            && function_exists('sc_llm_generate_chat_name')
            && function_exists('sc_history_rename')) {
            $name_seed = array(
                array('role' => 'user',      'text' => $message),
                array('role' => 'assistant', 'text' => $assistant),
            );
            $name = sc_llm_generate_chat_name($provider, $name_seed);
            if (is_string($name) && $name !== '') {
                sc_history_rename($chat_id, $name);
                $chat_name = $name;
            }
        }

        echo "data: " . json_encode(
            array('done' => true, 'chat_name' => $chat_name)) . "\n\n";
        exit;
    }
}

/* sc_api_chat_handle_regenerate_stream($cfg, $body)
 *   "regenerate" action in streaming mode. */
if (!function_exists('sc_api_chat_handle_regenerate_stream')) {
    function sc_api_chat_handle_regenerate_stream($cfg, $body) {
        $chat_id = sc_api_chat_chat_id($body);
        if (!function_exists('sc_history_chat_dir')
            || sc_history_chat_dir($chat_id) === '') {
            echo "data: " . json_encode(array('error' => 'bad_chat_id'))
               . "\n\n";
            exit;
        }
        $dir = sc_history_chat_dir($chat_id);
        if (!is_dir($dir)) {
            echo "data: " . json_encode(array('error' => 'chat_not_found'))
               . "\n\n";
            exit;
        }
        if (!function_exists('sc_llm_dispatch')) {
            echo "data: " . json_encode(array('error' => 'llm_unavailable'))
               . "\n\n";
            exit;
        }

        sc_api_chat_delete_last_assistant($chat_id);
        $hist = array();
        if (function_exists('sc_history_load_messages')) {
            $hist = sc_history_load_messages($chat_id);
        }
        $providers = sc_api_chat_load_providers();
        $provider  = sc_api_chat_resolve_provider(
            $providers, $cfg, $chat_id, ''
        );
        if ($provider === null) {
            echo "data: " . json_encode(
                array('error' => 'provider_not_found')) . "\n\n";
            exit;
        }
        $timeout = sc_api_chat_provider_timeout($provider);

        $llm_msgs = sc_api_chat_messages_to_llm($hist);
        $system   = sc_api_chat_load_system_prompt($chat_id);

        if (!class_exists('SC_StreamAccumulator')) {
            class SC_StreamAccumulator {
                public static $content = '';
                public static function callback($chunk, $event) {
                    self::$content .= $chunk;
                    echo "data: " . json_encode(
                        array('content' => $chunk)) . "\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            }
        }

        SC_StreamAccumulator::$content = '';

        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }
        while (ob_get_level()) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        if (sc_api_chat_provider_stream($provider)) {
            $result = sc_llm_dispatch(
                $provider, $llm_msgs, $system,
                array('SC_StreamAccumulator', 'callback')
            );
            $assistant = SC_StreamAccumulator::$content;
        } else {
            $start  = microtime(true);
            $result = sc_llm_dispatch($provider, $llm_msgs, $system, null);
            $elapsed = (microtime(true) - $start) * 1000;
            $timeout_err = sc_api_chat_check_timeout(
                $elapsed, $timeout, $result
            );
            if ($timeout_err !== false) {
                $result = array('ok' => false, 'error' => $timeout_err);
            }
            $assistant = '';
            if (is_array($result) && !empty($result['ok'])
                && isset($result['content'])) {
                $assistant = (string)$result['content'];
            }
            if ($assistant !== '') {
                echo "data: " . json_encode(
                    array('content' => $assistant)) . "\n\n";
                flush();
            }
        }
        $stream_err = sc_api_chat_stream_result_error($result);
        if ($assistant === '' && $stream_err !== '') {
            echo "data: " . json_encode(array('error' => $stream_err))
               . "\n\n";
            exit;
        }
        if ($assistant !== ''
            && function_exists('sc_history_append_message')) {
            sc_history_append_message($chat_id, 'assistant', $assistant);
        }

        echo "data: " . json_encode(array('done' => true)) . "\n\n";
        exit;
    }
}

/* ---- main dispatch ---------------------------------------------- */
if (defined('SC_API_CHAT_NO_ENTRY') && SC_API_CHAT_NO_ENTRY) {
    return;
}

$cfg = sc_api_chat_load_cfg();

/* 1. Auth gate. Every action below assumes a valid session. */
if (!sc_api_chat_is_authorized($cfg)) {
    sc_api_chat_emit(401, array('ok' => false, 'error' => 'auth_required'));
}

/* 2. Method gate: POST only. */
$method = 'GET';
if (isset($_SERVER['REQUEST_METHOD'])
    && is_string($_SERVER['REQUEST_METHOD'])) {
    $method = strtoupper($_SERVER['REQUEST_METHOD']);
}
if ($method !== 'POST') {
    sc_api_chat_emit(405, array('ok' => false,
                                 'error' => 'method_not_allowed'));
}

$body   = sc_api_chat_read_body();
$action = sc_api_chat_action($body);

if ($action === '') {
    if (isset($body['conversation_id']) || isset($body['chat_id'])
        || isset($body['id'])) {
        $action = 'send';
    }
}

$is_stream = false;
$accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
if (strpos($accept, 'text/event-stream') !== false) {
    $is_stream = true;
}
if (is_array($body) && isset($body['stream']) && $body['stream']) {
    $is_stream = true;
}

if ($action === 'send') {
    if ($is_stream) {
        sc_api_chat_handle_send_stream($cfg, $body);
    } else {
        sc_api_chat_emit(200, sc_api_chat_handle_send($cfg, $body));
    }
}
if ($action === 'connect_check' || $action === 'test') {
    sc_api_chat_emit(200, sc_api_chat_handle_connect_check($cfg, $body));
}
if ($action === 'regenerate') {
    if ($is_stream) {
        sc_api_chat_handle_regenerate_stream($cfg, $body);
    } else {
        sc_api_chat_emit(200, sc_api_chat_handle_regenerate($cfg, $body));
    }
}
sc_api_chat_emit(400, array('ok' => false, 'error' => 'unknown_action'));
