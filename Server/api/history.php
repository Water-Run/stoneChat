<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/api/history.php
 *
 * Single-file JSON API for chat history. All responses are JSON.
 * Routing is HTTP-method-driven:
 *
 *   GET   (no id)              -> list all conversations
 *                                body: {conversations: [{id,title,...}]}
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
 * All actions require a valid session cookie (validated against
 * [User NAME] passwords). Missing/invalid cookies return 401
 * {ok:false, error:'auth_required'}. Every chat id is checked with
 * sc_history_validate_id() before any disk operation; bad ids
 * return 400 {ok:false, error:'bad_id'}.
 *
 * PHP 5.2 compatible (no closures, no [] array syntax, no
 * json_last_error, no http_response_code).
 * ------------------------------------------------------------------------- */

/* ---- module includes -------------------------------------------- */
require_once dirname(__FILE__) . '/../boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../auth.php';
require_once dirname(__FILE__) . '/../history.php';
require_once dirname(__FILE__) . '/../i18n.php';

/* ---- generic helpers (all sc_api_history_*-prefixed, guarded) --- */

/* sc_api_history_emit($status, $payload)
 *   Send a JSON response with the given status and exit. Uses
 *   header() (not http_response_code, unavailable on PHP 5.2). */
if (!function_exists('sc_api_history_emit')) {
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

/* sc_api_history_status_reason($status)
 *   Map numeric HTTP status to its canonical reason phrase. */
if (!function_exists('sc_api_history_status_reason')) {
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

/* sc_api_history_load_cfg()
 *   Load CONF.ini from the project root, or return an empty array. */
if (!function_exists('sc_api_history_load_cfg')) {
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

/* sc_api_history_auth_context($cfg)
 *   Return username for the current session cookie. */
if (!function_exists('sc_api_history_auth_context')) {
    function sc_api_history_auth_context($cfg) {
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

/* sc_api_history_is_authorized($cfg)
 *   Check that the request carries a valid session cookie. */
if (!function_exists('sc_api_history_is_authorized')) {
    function sc_api_history_is_authorized($cfg) {
        $ctx = sc_api_history_auth_context($cfg);
        return !empty($ctx['ok']);
    }
}

/* sc_api_history_read_body()
 *   Read the request body, accepting both JSON and form-encoded.
 *   Form-encoded is required for IE6 compatibility. */
if (!function_exists('sc_api_history_read_body')) {
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

/* sc_api_history_str($src, $key, $default)
 *   Fetch a string field from an assoc array, with fallback. */
if (!function_exists('sc_api_history_str')) {
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

/* sc_api_history_chat_id($body)
 *   Read a chat id from "id", "chat_id", or "conversation_id". */
if (!function_exists('sc_api_history_chat_id')) {
    function sc_api_history_chat_id($body) {
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

/* sc_api_history_action($body)
 *   Resolve the action name from the request body. */
if (!function_exists('sc_api_history_action')) {
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

/* sc_api_history_request_method()
 *   Return the HTTP method, uppercased. Defaults to 'GET'. */
if (!function_exists('sc_api_history_request_method')) {
    function sc_api_history_request_method() {
        if (isset($_SERVER['REQUEST_METHOD'])
            && is_string($_SERVER['REQUEST_METHOD'])) {
            return strtoupper($_SERVER['REQUEST_METHOD']);
        }
        return 'GET';
    }
}

/* sc_api_history_default_title()
 *   Build a placeholder display title for a fresh chat. */
if (!function_exists('sc_api_history_default_title')) {
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

/* ---- action handlers (each returns a response array) ----------- */

/* sc_api_history_handle_list($cfg)
 *   List every conversation in HISTORY/, newest first. */
if (!function_exists('sc_api_history_handle_list')) {
    function sc_api_history_handle_list($cfg) {
        $rows = array();
        if (function_exists('sc_history_list')) {
            $rows = sc_history_list($cfg);
        }
        return array('conversations' => is_array($rows) ? $rows : array());
    }
}

/* sc_api_history_handle_get($chat_id)
 *   Load one conversation (meta + system + messages). */
if (!function_exists('sc_api_history_handle_get')) {
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

/* sc_api_history_handle_new($body)
 *   Create a new conversation and return its id. */
if (!function_exists('sc_api_history_handle_new')) {
    function sc_api_history_handle_new($body, $cfg) {
        if (!function_exists('sc_history_create')) {
            return array('ok' => false, 'error' => 'history_unavailable');
        }
        $provider = sc_api_history_str($body, 'provider_id', '');
        $ctx = sc_api_history_auth_context($cfg);
        if ($provider !== '' && !empty($ctx['ok'])
            && function_exists('sc_auth_provider_allowed')) {
            $username = isset($ctx['username']) ? (string)$ctx['username'] : '';
            $model_id = $provider;
            if (function_exists('sc_load_providers')) {
                $rows = sc_load_providers(dirname(__FILE__) . DIRECTORY_SEPARATOR
                    . '..' . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . 'CONF.ini');
                if (is_array($rows)) {
                    for ($i = 0; $i < count($rows); $i++) {
                        if (isset($rows[$i]['id'])
                            && (string)$rows[$i]['id'] === $provider
                            && function_exists('sc_auth_provider_model_id')) {
                            $model_id = sc_auth_provider_model_id($rows[$i]);
                            break;
                        }
                    }
                }
            }
            if (!sc_auth_provider_allowed($cfg, $model_id, $username)) {
                return array('ok' => false, 'error' => 'provider_not_allowed');
            }
        }
        $model    = sc_api_history_str($body, 'model', '');
        $new_id   = sc_history_create($provider, $model);
        if (!is_string($new_id) || $new_id === '') {
            return array('ok' => false, 'error' => 'create_failed');
        }
        $title = sc_api_history_default_title();
        return array('ok' => true, 'id' => $new_id, 'title' => $title);
    }
}

/* sc_api_history_handle_save($body)
 *   Append a message to a conversation. Role is normalised to
 *   "user" or "assistant"; anything else is role_invalid. */
if (!function_exists('sc_api_history_handle_save')) {
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

/* sc_api_history_handle_rename($body)
 *   Rename a conversation (display name in meta.txt). */
if (!function_exists('sc_api_history_handle_rename')) {
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

/* sc_api_history_handle_set_system($body)
 *   Set (or clear, with empty text) the system prompt of a chat. */
if (!function_exists('sc_api_history_handle_set_system')) {
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

/* sc_api_history_handle_delete($chat_id)
 *   Delete a conversation (Recycle Bin on Windows, recursive
 *   unlink elsewhere). */
if (!function_exists('sc_api_history_handle_delete')) {
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

/* ---- main dispatch ---------------------------------------------- */

$cfg = sc_api_history_load_cfg();

/* 1. Auth gate. Every action below assumes a valid session. */
if (!sc_api_history_is_authorized($cfg)) {
    sc_api_history_emit(401, array('ok' => false, 'error' => 'auth_required'));
}

$method = sc_api_history_request_method();

/* 2. GET: list (no id) or detail (with id). */
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

/* 3. POST: action-driven. Each action validates the chat id. */
if ($method === 'POST') {
    $body   = sc_api_history_read_body();
    $action = sc_api_history_action($body);

    /* action-specific id validation. Required for everything
     * except "new". This guards against path-traversal payloads
     * sneaking through with a legitimate action name. */
    if ($action !== 'new' && $action !== 'create'
        && $action !== 'list' && $action !== 'get') {
        $cid = sc_api_history_chat_id($body);
        if (!function_exists('sc_history_validate_id')
            || !sc_history_validate_id($cid)) {
            sc_api_history_emit(400, array('ok' => false, 'error' => 'bad_id'));
        }
    }

    if ($action === 'new' || $action === 'create') {
        sc_api_history_emit(200, sc_api_history_handle_new($body, $cfg));
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

/* 4. DELETE: always takes ?id= or ?chat_id=. */
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

/* 5. Anything else (PUT, OPTIONS, ...) is not supported. */
sc_api_history_emit(405, array('ok' => false, 'error' => 'method_not_allowed'));
