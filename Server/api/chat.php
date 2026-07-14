<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/api/chat.php
 *
 * Single-file JSON API for chat operations. Routing is body-driven
 * via the "action" field. Every request must be POST and carry a
 * valid session cookie plus action-specific csrf_token (validated
 * against [User NAME] passwords in constant time).
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
 *       - persist the assistant reply when the provider returns content
 *       response: { "ok": true,  "assistant": "<text>",
 *                   "chat_name": "<string, possibly empty>" }
 *                or { "ok": false, "error": "<code>" }
 *
 *   POST action="name"           { "chat_id": "<id>",
 *                                  "message": "<optional seed text>" }
 *       - ask the LLM for a short title and write it to meta.txt
 *         via sc_history_rename()
 *       response: { "ok": true, "chat_name": "<title>" }
 *
 *   POST action="connect_check"  { "provider_id": "<id>" }
 *       - ask the provider for a fixed, minimal "OK" reply via
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
 * Public helpers (sc_api_chat_-prefixed, function_exists guarded):
 *   sc_api_chat_emit($status, $payload)           emit JSON response
 *   sc_api_chat_status_reason($status)            HTTP status reason phrase
 *   sc_api_chat_load_cfg()                        load root CONF.ini
 *   sc_api_chat_auth_context($cfg)                get user auth context
 *   sc_api_chat_is_authorized($cfg)               check request cookie
 *   sc_api_chat_read_body()                       read raw JSON body
 *   sc_api_chat_str($src, $key, $default)         extract string value safely
 *   sc_api_chat_action($body)                     extract request action
 *   sc_api_chat_csrf_action($action)              CSRF action name map
 *   sc_api_chat_require_csrf($cfg, $act, $body)   validate CSRF token
 *   sc_api_chat_chat_id($body)                    validate & extract chat_id
 *   sc_api_chat_ini_path()                        get absolute path to CONF.ini
 *   sc_api_chat_load_providers($cfg)              load providers list
 *   sc_api_chat_find_provider($provs, $id)        find provider by id
 *   sc_api_chat_provider_timeout($provider)       get provider timeout
 *   sc_api_chat_provider_stream($provider)        check if provider streams
 *   sc_api_chat_resolve_provider(...)            resolve active provider row
 *   sc_api_chat_load_system_prompt($id)           read system.txt file
 *   sc_api_chat_messages_to_llm($messages)        map messages to LLM format
 *   sc_api_chat_delete_last_assistant($id)        delete last assistant turn
 *   sc_api_chat_check_timeout(...)                evaluate API call time limit
 *   sc_api_chat_handle_send($cfg, $body)          execute "send" action (non-stream)
 *   sc_api_chat_handle_name($cfg, $body)          execute "name" action (title)
 *   sc_api_chat_handle_connect_check($cfg, $body) execute "connect_check" action
 *   sc_api_chat_handle_regenerate($cfg, $body)    execute "regenerate" action
 *   sc_api_chat_stream_result_error($result)      extract stream error code
 *   sc_api_chat_stream_headers()                  set headers for EventSource
 *   sc_api_chat_handle_send_stream($cfg, $body)   execute "send" action (streaming)
 *   sc_api_chat_handle_regenerate_stream(...)    execute "regenerate" (streaming)
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
                403 => 'Forbidden',
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

/* sc_api_chat_auth_context($cfg)
 *   Return the current authenticated username from the session
 *   cookie. */
if (!function_exists('sc_api_chat_auth_context')) {
    function sc_api_chat_auth_context($cfg) {
        $bad = array('ok' => false, 'username' => '');
        if (!is_array($cfg)) {
            return $bad;
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
            return $bad;
        }
        if (function_exists('sc_auth_token_context')) {
            return sc_auth_token_context($token, $cfg);
        }
        return $bad;
    }
}

/* sc_api_chat_is_authorized($cfg)
 *   Check that the request carries a valid session cookie. */
if (!function_exists('sc_api_chat_is_authorized')) {
    function sc_api_chat_is_authorized($cfg) {
        $ctx = sc_api_chat_auth_context($cfg);
        return !empty($ctx['ok']);
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

if (!function_exists('sc_api_chat_csrf_action')) {
    function sc_api_chat_csrf_action($action) {
        $a = strtolower(trim((string)$action));
        if ($a === 'send') {
            return 'chat:send';
        }
        if ($a === 'name') {
            return 'chat:name';
        }
        if ($a === 'regenerate') {
            return 'chat:regenerate';
        }
        if ($a === 'connect_check' || $a === 'test') {
            return 'chat:test';
        }
        return '';
    }
}

if (!function_exists('sc_api_chat_require_csrf')) {
    function sc_api_chat_require_csrf($cfg, $csrf_action, $body) {
        if ($csrf_action === '') {
            return true;
        }
        $session_token = '';
        if (function_exists('sc_auth_session_token_from_cookie')) {
            $session_token = sc_auth_session_token_from_cookie($cfg);
        }
        $posted = '';
        if (is_array($body) && isset($body['csrf_token'])) {
            $posted = (string)$body['csrf_token'];
        }
        return ($session_token !== ''
                && function_exists('sc_auth_csrf_verify')
                && sc_auth_csrf_verify($session_token, $csrf_action, $posted));
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
    function sc_api_chat_load_providers($cfg) {
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
        $ctx = sc_api_chat_auth_context($cfg);
        if (!empty($ctx['ok']) && function_exists('sc_auth_filter_providers')) {
            $username = isset($ctx['username']) ? (string)$ctx['username'] : '';
            $out = sc_auth_filter_providers($out, $cfg, $username);
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
 *   assistant turn.
 *
 *   Order of operations:
 *     1. Save the user message.
 *     2. Dispatch to sc_llm_chat().
 *     3a. On success: save the assistant message.
 *     3b. On failure: roll back the user message file so the next
 *         send attempt does not deliver two consecutive user turns
 *         to the LLM (violates the alternating-role contract -- A3). */
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
        $providers = sc_api_chat_load_providers($cfg);
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
        /* 1. persist the user message. Record the index so we can
         *    roll it back if the LLM call fails (A3 fix). */
        if (!function_exists('sc_history_append_message')) {
            return array('ok' => false, 'error' => 'history_write_failed');
        }
        $user_idx = sc_history_append_message($chat_id, 'user', $message);
        if ($user_idx < 1) {
            return array('ok' => false, 'error' => 'history_write_failed');
        }

        /* 2. build the LLM request and dispatch. */
        $llm_msgs  = sc_api_chat_messages_to_llm($hist);
        $llm_msgs[] = array('role' => 'user', 'content' => $message);
        $system    = sc_api_chat_load_system_prompt($chat_id);
        $start     = microtime(true);
        $result    = sc_llm_chat($provider, $llm_msgs, $system);
        $elapsed   = (microtime(true) - $start) * 1000;

        /* 3. evaluate result. */
        $assistant = '';
        $chat_name = '';
        $ok_payload = false;
        $err = 'unknown';
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

        if (!$ok_payload) {
            /* 3b. Roll back the orphan user message so the next send
             *     does not produce two consecutive user turns. */
            if (function_exists('sc_history_message_filename')) {
                $rollback = sc_history_message_filename('user', $user_idx);
                if ($rollback !== '') {
                    @unlink($dir . DIRECTORY_SEPARATOR . $rollback);
                }
            }
            return array('ok' => false, 'error' => $err);
        }

        /* 3a. Save the assistant message. */
        if ($assistant !== ''
            && function_exists('sc_history_append_message')) {
            if (sc_history_append_message($chat_id, 'assistant',
                                          $assistant) < 1) {
                return array('ok' => false,
                             'error' => 'history_write_failed');
            }
        }

        return array(
            'ok'        => true,
            'assistant' => $assistant,
            'chat_name' => $chat_name,
        );
    }
}

/* sc_api_chat_handle_name($cfg, $body)
 *   "name": manual title request. The browser calls this from the
 *   [Title] button. It uses the current chat history when the browser
 *   does not send a seed message. */
if (!function_exists('sc_api_chat_handle_name')) {
    function sc_api_chat_handle_name($cfg, $body) {
        $chat_id = sc_api_chat_chat_id($body);
        $message = sc_api_chat_str($body, 'message', '');
        if ($message === '') {
            $message = sc_api_chat_str($body, 'content', '');
        }
        if (!function_exists('sc_history_chat_dir')
            || sc_history_chat_dir($chat_id) === '') {
            return array('ok' => false, 'error' => 'bad_chat_id');
        }
        $dir = sc_history_chat_dir($chat_id);
        if (!is_dir($dir)) {
            return array('ok' => false, 'error' => 'chat_not_found');
        }
        if (!function_exists('sc_llm_generate_chat_name')
            || !function_exists('sc_history_rename')) {
            return array('ok' => false, 'error' => 'llm_unavailable');
        }
        $name_seed = array();
        if ($message !== '') {
            $name_seed[] = array('role' => 'user', 'text' => $message);
        } elseif (function_exists('sc_history_load_messages')) {
            $name_seed = sc_history_load_messages($chat_id);
        }
        if (empty($name_seed)) {
            return array('ok' => false, 'error' => 'empty_message');
        }
        $providers = sc_api_chat_load_providers($cfg);
        $provider  = sc_api_chat_resolve_provider($providers, $cfg,
                                                  $chat_id, '');
        if ($provider === null) {
            return array('ok' => false, 'error' => 'provider_not_found');
        }
        $name = sc_llm_generate_chat_name($provider, $name_seed);
        if (!is_string($name) || $name === '') {
            return array('ok' => false, 'error' => 'name_failed');
        }
        sc_history_rename($chat_id, $name);
        return array('ok' => true, 'chat_name' => $name);
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
        $providers = sc_api_chat_load_providers($cfg);
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
        $messages = array(array(
            'role' => 'user',
            'content' => 'Reply with OK only.',
        ));
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
        $providers = sc_api_chat_load_providers($cfg);
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
        if ($assistant !== '') {
            if (!function_exists('sc_history_append_message')
                || sc_history_append_message($chat_id, 'assistant',
                                             $assistant) < 1) {
                return array('ok' => false,
                             'error' => 'history_write_failed');
            }
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

/* sc_api_chat_stream_headers()
 *   Send SSE headers before any stream event, including early errors. */
if (!function_exists('sc_api_chat_stream_headers')) {
    function sc_api_chat_stream_headers() {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }
    }
}

/* sc_api_chat_handle_send_stream($cfg, $body)
 *   "send" action in streaming mode. */
if (!function_exists('sc_api_chat_handle_send_stream')) {
    function sc_api_chat_handle_send_stream($cfg, $body) {
        sc_api_chat_stream_headers();
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

        $providers = sc_api_chat_load_providers($cfg);
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
        if (!function_exists('sc_history_append_message')) {
            echo "data: " . json_encode(
                array('error' => 'history_write_failed')) . "\n\n";
            exit;
        }
        $user_idx = sc_history_append_message($chat_id, 'user', $message);
        if ($user_idx < 1) {
            echo "data: " . json_encode(
                array('error' => 'history_write_failed')) . "\n\n";
            exit;
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
        if ($stream_err !== '') {
            /* Roll back the orphan user message (A3 fix). In streaming
             * mode chunks may already have been flushed to the client
             * so the response cannot be undone, but the history file
             * must be cleaned up to preserve the alternating-role order. */
            if (function_exists('sc_history_message_filename')) {
                $rollback = sc_history_message_filename('user', $user_idx);
                if ($rollback !== '') {
                    @unlink($dir . DIRECTORY_SEPARATOR . $rollback);
                }
            }
            echo "data: " . json_encode(array('error' => $stream_err))
               . "\n\n";
            exit;
        }
        if ($assistant !== ''
            && function_exists('sc_history_append_message')) {
            if (sc_history_append_message($chat_id, 'assistant',
                                          $assistant) < 1) {
                echo "data: " . json_encode(
                    array('error' => 'history_write_failed')) . "\n\n";
                exit;
            }
        }

        echo "data: " . json_encode(
            array('done' => true, 'chat_name' => '')) . "\n\n";
        exit;
    }
}

/* sc_api_chat_handle_regenerate_stream($cfg, $body)
 *   "regenerate" action in streaming mode. */
if (!function_exists('sc_api_chat_handle_regenerate_stream')) {
    function sc_api_chat_handle_regenerate_stream($cfg, $body) {
        sc_api_chat_stream_headers();
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
        $providers = sc_api_chat_load_providers($cfg);
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
        if ($stream_err !== '') {
            echo "data: " . json_encode(array('error' => $stream_err))
               . "\n\n";
            exit;
        }
        if ($assistant !== ''
            && function_exists('sc_history_append_message')) {
            if (sc_history_append_message($chat_id, 'assistant',
                                          $assistant) < 1) {
                echo "data: " . json_encode(
                    array('error' => 'history_write_failed')) . "\n\n";
                exit;
            }
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
$auth_ctx = sc_api_chat_auth_context($cfg);
if (empty($auth_ctx['ok'])) {
    sc_api_chat_emit(401, array('ok' => false, 'error' => 'auth_required'));
}
if (function_exists('sc_history_set_user')) {
    $auth_user = isset($auth_ctx['username'])
                 ? (string)$auth_ctx['username'] : '';
    sc_history_set_user($auth_user);
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

$csrf_action = sc_api_chat_csrf_action($action);
if (!sc_api_chat_require_csrf($cfg, $csrf_action, $body)) {
    sc_api_chat_emit(403, array('ok' => false,
                                'error' => 'csrf_required'));
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
if ($action === 'name') {
    sc_api_chat_emit(200, sc_api_chat_handle_name($cfg, $body));
}
if ($action === 'regenerate') {
    if ($is_stream) {
        sc_api_chat_handle_regenerate_stream($cfg, $body);
    } else {
        sc_api_chat_emit(200, sc_api_chat_handle_regenerate($cfg, $body));
    }
}
sc_api_chat_emit(400, array('ok' => false, 'error' => 'unknown_action'));
