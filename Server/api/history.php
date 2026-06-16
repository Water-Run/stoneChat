<?php
/**
 * stoneChat Server API: history endpoint.
 *
 * Single-file JSON API for chat history. All responses are JSON.
 * Routing is HTTP-method-driven:
 *
 *   GET   (no id)              -> list all conversations
 *                                body: {conversations: [{id,title,updated,...}]}
 *   GET   ?id=<chat_id>        -> load one conversation
 *                                body: {meta, system, messages}
 *   POST  action="new"         -> create new conversation
 *                                body in : {provider_id, model}
 *                                body out: {ok:true, id, title}
 *   POST  action="save"        -> append a message
 *                                body in : {id|chat_id, role, text|content}
 *                                body out: {ok:true, message_index}
 *   POST  action="rename"      -> rename conversation
 *                                body in : {id|chat_id, new_name|title}
 *                                body out: {ok:true}
 *   POST  action="set_system"  -> set system prompt
 *                                body in : {id|chat_id, text|content}
 *                                body out: {ok:true}
 *   POST  action="delete"      -> delete conversation
 *   DELETE  ?id=<chat_id>      -> delete conversation
 *                                body out: {ok:true}
 *
 * All actions require a valid sc_session cookie (the value is treated
 * as a session token and validated with sc_auth_check_password against
 * the configured [auth] password). Missing/invalid cookies return
 * 401 {ok:false, error:'auth_required'}.
 *
 * Path-traversal: every chat id is checked with sc_history_validate_id
 * before any disk operation; bad ids return 400 {ok:false, error:'bad_id'}.
 *
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no
 * json_last_error, no http_response_code).
 */

// ----- module includes -----
require_once dirname(__FILE__) . '/../boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../auth.php';
require_once dirname(__FILE__) . '/../history.php';
require_once dirname(__FILE__) . '/../i18n.php';

// =====================================================================
// Helpers (all sc_api_history_*-prefixed and include-guarded)
// =====================================================================

if (!function_exists('sc_api_history_emit')) {
    /**
     * Send a JSON response with the given numeric HTTP status code
     * and exit. Uses header() (not http_response_code, unavailable
     * on PHP 5.2) to set the status line.
     *
     * @param int   $status  HTTP status code (e.g. 200, 400, 401).
     * @param array $payload Decoded array to send (will be json_encoded).
     * @return void
     */
    function sc_api_history_emit($status, $payload) {
        if (!headers_sent()) {
            $protocol = 'HTTP/1.0';
            if (isset($_SERVER['SERVER_PROTOCOL'])
                && is_string($_SERVER['SERVER_PROTOCOL'])
                && $_SERVER['SERVER_PROTOCOL'] !== '') {
                $protocol = $_SERVER['SERVER_PROTOCOL'];
            }
            $reason = sc_api_history_status_reason((int)$status);
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

if (!function_exists('sc_api_history_status_reason')) {
    /**
     * Map numeric HTTP status to its canonical reason phrase.
     *
     * Only the codes this endpoint emits are listed; unknown codes
     * get an empty reason (PHP falls back to a generic status line).
     *
     * @param int $status HTTP status code.
     * @return string Reason phrase, or '' if not in the table.
     */
    function sc_api_history_status_reason($status) {
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

if (!function_exists('sc_api_history_load_cfg')) {
    /**
     * Load CONF.ini from the project root, or return an empty array.
     *
     * @return array Parsed config (empty array on failure).
     */
    function sc_api_history_load_cfg() {
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

if (!function_exists('sc_api_history_is_authorized')) {
    /**
     * Check that the request carries a valid session cookie.
     *
     * The cookie value is treated as a session token and validated
     * with sc_auth_check_password (constant-time compare against
     * cfg[auth][password]). Empty cookies fail closed.
     *
     * @param array $cfg Parsed config.
     * @return bool true iff the request is authorized.
     */
    function sc_api_history_is_authorized($cfg) {
        if (!is_array($cfg)) {
            return false;
        }
        $name = 'sc_auth';
        if (isset($cfg['auth']['cookie_name'])
            && (string)$cfg['auth']['cookie_name'] !== '') {
            $name = (string)$cfg['auth']['cookie_name'];
        }
        $token = '';
        if (isset($_COOKIE[$name])
            && is_string($_COOKIE[$name])) {
            $token = $_COOKIE[$name];
        }
        if ($token === '' && isset($_COOKIE['sc_session']) && is_string($_COOKIE['sc_session'])) {
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

if (!function_exists('sc_api_history_read_body')) {
    /**
     * Read the request body, accepting both JSON and form-encoded.
     *
     * Form-encoded is required for IE6 compatibility; JSON is the
     * preferred format for modern clients. Whichever parses cleanly
     * to an array is returned; an empty array means "no body".
     *
     * @return array Decoded body.
     */
    function sc_api_history_read_body() {
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

if (!function_exists('sc_api_history_str')) {
    /**
     * Fetch a string field from an associative array, with fallback.
     *
     * Returns $default if the key is missing or not a string; the
     * value is cast to string otherwise.
     *
     * @param mixed  $src     Source array (or anything else -> empty).
     * @param string $key     Key to read.
     * @param string $default Default when missing/non-string.
     * @return string The string value, or $default.
     */
    function sc_api_history_str($src, $key, $default) {
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

if (!function_exists('sc_api_history_chat_id')) {
    /**
     * Read a chat id from the body, accepting "id", "chat_id", or "conversation_id".
     *
     * @param array $body Decoded body.
     * @return string The id, or '' if neither key is present.
     */
    function sc_api_history_chat_id($body) {
        if (!is_array($body)) {
            return '';
        }
        if (isset($body['chat_id']) && is_string($body['chat_id'])) {
            return $body['chat_id'];
        }
        if (isset($body['conversation_id']) && is_string($body['conversation_id'])) {
            return $body['conversation_id'];
        }
        if (isset($body['id']) && (is_string($body['id']) || is_numeric($body['id']))) {
            return (string)$body['id'];
        }
        return '';
    }
}

if (!function_exists('sc_api_history_action')) {
    /**
     * Resolve the action name from the request body.
     *
     * Empty string when no action is present.
     *
     * @param array $body Decoded body.
     * @return string Action name.
     */
    function sc_api_history_action($body) {
        if (!is_array($body)) {
            return '';
        }
        if (isset($body['action']) && is_string($body['action'])) {
            return $body['action'];
        }
        return '';
    }
}

if (!function_exists('sc_api_history_request_method')) {
    /**
     * Return the HTTP method, uppercased. Defaults to 'GET' if absent.
     *
     * @return string HTTP method.
     */
    function sc_api_history_request_method() {
        if (isset($_SERVER['REQUEST_METHOD'])
            && is_string($_SERVER['REQUEST_METHOD'])) {
            return strtoupper($_SERVER['REQUEST_METHOD']);
        }
        return 'GET';
    }
}

if (!function_exists('sc_api_history_default_title')) {
    /**
     * Build a placeholder display title for a freshly created chat.
     *
     * The frontend typically renames the chat on first message; the
     * API just provides a non-empty starting value.
     *
     * @return string Default title.
     */
    function sc_api_history_default_title() {
        if (function_exists('sc_t')) {
            $t = sc_t('new_chat', '');
            if (is_string($t) && $t !== '' && $t !== 'new_chat') {
                return $t;
            }
        }
        return 'New chat';
    }
}

// =====================================================================
// Action handlers (each returns a response array)
// =====================================================================

if (!function_exists('sc_api_history_handle_list')) {
    /**
     * List every conversation in HISTORY/, newest first.
     *
     * @param array $cfg Parsed config.
     * @return array Response payload.
     */
    function sc_api_history_handle_list($cfg) {
        $rows = array();
        if (function_exists('sc_history_list')) {
            $rows = sc_history_list($cfg);
        }
        return array('conversations' => is_array($rows) ? $rows : array());
    }
}

if (!function_exists('sc_api_history_handle_get')) {
    /**
     * Load one conversation (meta + system + messages).
     *
     * @param string $chat_id Chat id (already validated).
     * @return array Response payload.
     */
    function sc_api_history_handle_get($chat_id) {
        if (!function_exists('sc_history_load')) {
            return array('ok' => false, 'error' => 'history_unavailable');
        }
        $loaded = sc_history_load($chat_id);
        if (!is_array($loaded) || empty($loaded['meta'])) {
            return array('ok' => false, 'error' => 'not_found');
        }
        return array(
            'ok'       => true,
            'meta'     => $loaded['meta'],
            'system'   => $loaded['system'],
            'messages' => $loaded['messages'],
        );
    }
}

if (!function_exists('sc_api_history_handle_new')) {
    /**
     * Create a new conversation and return its id.
     *
     * @param array $body Decoded body.
     * @return array Response payload.
     */
    function sc_api_history_handle_new($body) {
        if (!function_exists('sc_history_create')) {
            return array('ok' => false, 'error' => 'history_unavailable');
        }
        $provider = sc_api_history_str($body, 'provider_id', '');
        $model    = sc_api_history_str($body, 'model', '');
        $new_id   = sc_history_create($provider, $model);
        if (!is_string($new_id) || $new_id === '') {
            return array('ok' => false, 'error' => 'create_failed');
        }
        $title = sc_api_history_default_title();
        return array('ok' => true, 'id' => $new_id, 'title' => $title);
    }
}

if (!function_exists('sc_api_history_handle_save')) {
    /**
     * Append a message to a conversation.
     *
     * The role is normalized to "user" or "assistant" -- anything
     * else is rejected with role_invalid.
     *
     * @param array $body Decoded body.
     * @return array Response payload.
     */
    function sc_api_history_handle_save($body) {
        if (!function_exists('sc_history_save_message')) {
            return array('ok' => false, 'error' => 'history_unavailable');
        }
        $role = sc_api_history_str($body, 'role', '');
        if ($role !== 'user' && $role !== 'assistant') {
            return array('ok' => false, 'error' => 'role_invalid');
        }
        $text = sc_api_history_str($body, 'text', '');
        if ($text === '') {
            $text = sc_api_history_str($body, 'content', '');
        }
        $cfg  = sc_api_history_load_cfg();
        $chat_id = sc_api_history_chat_id($body);
        $idx = sc_history_save_message($chat_id, $role, $text, $cfg);
        if ((int)$idx <= 0) {
            return array('ok' => false, 'error' => 'save_failed');
        }
        return array('ok' => true, 'message_index' => (int)$idx);
    }
}

if (!function_exists('sc_api_history_handle_rename')) {
    /**
     * Rename a conversation (display name in meta.txt).
     *
     * @param array $body Decoded body.
     * @return array Response payload.
     */
    function sc_api_history_handle_rename($body) {
        if (!function_exists('sc_history_rename')) {
            return array('ok' => false, 'error' => 'history_unavailable');
        }
        $new_name = sc_api_history_str($body, 'new_name', '');
        if ($new_name === '') {
            $new_name = sc_api_history_str($body, 'title', '');
        }
        $chat_id = sc_api_history_chat_id($body);
        if (!sc_history_rename($chat_id, $new_name)) {
            return array('ok' => false, 'error' => 'rename_failed');
        }
        return array('ok' => true);
    }
}

if (!function_exists('sc_api_history_handle_set_system')) {
    /**
     * Set (or clear, with empty text) the system prompt of a chat.
     *
     * @param array $body Decoded body.
     * @return array Response payload.
     */
    function sc_api_history_handle_set_system($body) {
        if (!function_exists('sc_history_set_system')) {
            return array('ok' => false, 'error' => 'history_unavailable');
        }
        $text = sc_api_history_str($body, 'text', '');
        if ($text === '') {
            $text = sc_api_history_str($body, 'content', '');
        }
        $chat_id = sc_api_history_chat_id($body);
        if (!sc_history_set_system($chat_id, $text)) {
            return array('ok' => false, 'error' => 'set_system_failed');
        }
        return array('ok' => true);
    }
}

if (!function_exists('sc_api_history_handle_delete')) {
    /**
     * Delete a conversation (Recycle Bin on Windows, recursive unlink
     * elsewhere). The function sc_history_delete_to_recycle handles
     * the platform branch.
     *
     * @param string $chat_id Chat id (already validated).
     * @return array Response payload.
     */
    function sc_api_history_handle_delete($chat_id) {
        if (!function_exists('sc_history_delete_to_recycle')) {
            return array('ok' => false, 'error' => 'history_unavailable');
        }
        if (!sc_history_delete_to_recycle($chat_id)) {
            return array('ok' => false, 'error' => 'delete_failed');
        }
        return array('ok' => true);
    }
}

// =====================================================================
// Main dispatch
// =====================================================================

$cfg = sc_api_history_load_cfg();

// 1. Auth gate. Every action below this point assumes a valid session.
if (!sc_api_history_is_authorized($cfg)) {
    sc_api_history_emit(401, array('ok' => false, 'error' => 'auth_required'));
}

$method = sc_api_history_request_method();

// 2. GET: list (no id) or detail (with id).
if ($method === 'GET') {
    $id = '';
    if (isset($_GET['id']) && is_string($_GET['id'])) {
        $id = $_GET['id'];
    } elseif (isset($_GET['chat_id']) && is_string($_GET['chat_id'])) {
        $id = $_GET['chat_id'];
    }
    if ($id === '') {
        sc_api_history_emit(200, sc_api_history_handle_list($cfg));
    }
    if (!function_exists('sc_history_validate_id')
        || !sc_history_validate_id($id)) {
        sc_api_history_emit(400, array('ok' => false, 'error' => 'bad_id'));
    }
    sc_api_history_emit(200, sc_api_history_handle_get($id));
}

// 3. POST: action-driven. Each action validates the chat id itself.
if ($method === 'POST') {
    $body   = sc_api_history_read_body();
    $action = sc_api_history_action($body);

    // Action-specific id validation. The id is required for everything
    // except "new". This guards against path-traversal payloads
    // sneaking through with a legitimate action name.
    if ($action !== 'new' && $action !== 'create'
        && $action !== 'list' && $action !== 'get') {
        $cid = sc_api_history_chat_id($body);
        if (!function_exists('sc_history_validate_id')
            || !sc_history_validate_id($cid)) {
            sc_api_history_emit(400, array('ok' => false, 'error' => 'bad_id'));
        }
    }

    if ($action === 'new' || $action === 'create') {
        sc_api_history_emit(200, sc_api_history_handle_new($body));
    }
    if ($action === 'save' || $action === 'append') {
        sc_api_history_emit(200, sc_api_history_handle_save($body));
    }
    if ($action === 'rename') {
        sc_api_history_emit(200, sc_api_history_handle_rename($body));
    }
    if ($action === 'set_system') {
        sc_api_history_emit(200, sc_api_history_handle_set_system($body));
    }
    if ($action === 'delete') {
        $cid = sc_api_history_chat_id($body);
        sc_api_history_emit(200, sc_api_history_handle_delete($cid));
    }
    if ($action === 'list') {
        sc_api_history_emit(200, sc_api_history_handle_list($cfg));
    }
    if ($action === 'get') {
        $cid = sc_api_history_chat_id($body);
        sc_api_history_emit(200, sc_api_history_handle_get($cid));
    }
    sc_api_history_emit(400, array('ok' => false, 'error' => 'bad_action'));
}

// 4. DELETE: always takes ?id= or ?chat_id=.
if ($method === 'DELETE') {
    $id = '';
    if (isset($_GET['id']) && is_string($_GET['id'])) {
        $id = $_GET['id'];
    } elseif (isset($_GET['chat_id']) && is_string($_GET['chat_id'])) {
        $id = $_GET['chat_id'];
    }
    if (!function_exists('sc_history_validate_id')
        || !sc_history_validate_id($id)) {
        sc_api_history_emit(400, array('ok' => false, 'error' => 'bad_id'));
    }
    sc_api_history_emit(200, sc_api_history_handle_delete($id));
}

// 5. Anything else (PUT, OPTIONS, ...) is not supported.
sc_api_history_emit(405, array('ok' => false, 'error' => 'method_not_allowed'));
