<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/api/auth.php
 *
 * POST endpoint for the login / logout / check flow. The browser
 * may not support FormData on IE6, so the raw body is read via
 * php://input and parsed as JSON.
 *
 * Wire format (request body, all keys optional except as noted):
 *   { "action": "login",  "password": "<string>" }   // default action
 *   { "action": "logout" }
 *   { "action": "check"  }
 *
 * Responses (always JSON, Content-Type: application/json; charset=UTF-8):
 *   login success     { "ok": true,  "lang": "<code>",
 *                       "username": "<name>" }
 *   login wrong pwd   { "ok": false, "error": "invalid",
 *                       "attempts_left": <int> }
 *   login locked out  { "ok": false, "error": "locked",
 *                       "locked": true,
 *                       "locked_until": <unix_ts> }    (HTTP 429)
 *   logout            { "ok": true }
 *   check logged in   { "ok": true,  "lang": "<code>" }
 *   check no session  { "ok": false, "error": "not_logged_in" }
 *
 * On successful login an HttpOnly session cookie is set. The cookie
 * name comes from cfg[auth][cookie_name] and its lifetime from
 * cfg[auth][cookie_expires] (0 = session cookie). The cookie value
 * is opaque: it does not encode the password.
 *
 * The password is never echoed in any response or log. Failure
 * responses never reveal whether the password was wrong vs. whether
 * the account is locked beyond the documented "locked" payload.
 *
 * PHP 5.2 compatible (no closures, no [] array syntax, no
 * json_last_error, function_exists guards on every helper).
 * ------------------------------------------------------------------------- */

/* ---- dependencies ----------------------------------------------- */
require_once dirname(__FILE__) . '/../boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../auth.php';
require_once dirname(__FILE__) . '/../i18n.php';

header('Content-Type: application/json; charset=UTF-8');

/* sc_api_auth_cfg_path()
 *   Absolute path to CONF.ini (project root). */
if (!function_exists('sc_api_auth_cfg_path')) {
    function sc_api_auth_cfg_path() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR
             . '..' . DIRECTORY_SEPARATOR
             . '..' . DIRECTORY_SEPARATOR
             . 'CONF.ini';
    }
}

/* sc_api_auth_load_cfg()
 *   Load the parsed stoneChat config. */
if (!function_exists('sc_api_auth_load_cfg')) {
    function sc_api_auth_load_cfg() {
        return sc_load_config(sc_api_auth_cfg_path());
    }
}

/* sc_api_auth_cookie_params($cfg)
 *   Resolve the session cookie's name and lifetime from cfg. */
if (!function_exists('sc_api_auth_cookie_params')) {
    function sc_api_auth_cookie_params($cfg) {
        $name    = 'sc_auth';
        $expires = 0;
        if (is_array($cfg) && isset($cfg['auth'])
            && is_array($cfg['auth'])) {
            if (isset($cfg['auth']['cookie_name'])
                && (string)$cfg['auth']['cookie_name'] !== '') {
                $name = (string)$cfg['auth']['cookie_name'];
            }
            if (isset($cfg['auth']['cookie_expires'])) {
                $exp = (int)$cfg['auth']['cookie_expires'];
                if ($exp > 0) {
                    $expires = $exp;
                }
            }
        }
        return array('name' => $name, 'expires' => $expires);
    }
}

/* sc_api_auth_cookie_value($cfg, $user)
 *   Build a signed session cookie value (opaque, "scv1:" prefix). */
if (!function_exists('sc_api_auth_cookie_value')) {
    function sc_api_auth_cookie_value($cfg, $user) {
        if (function_exists('sc_auth_generate_token')) {
            return sc_auth_generate_token($cfg, $user);
        }
        $r1 = function_exists('mt_rand') ? mt_rand() : rand();
        $r2 = function_exists('mt_rand') ? mt_rand() : rand();
        $r3 = function_exists('mt_rand') ? mt_rand() : rand();
        return 'scv1:' . md5($r1 . '|' . $r2 . '|' . $r3 . '|' . time());
    }
}

/* sc_api_auth_set_cookie($cfg, $user)
 *   Issue the HttpOnly session cookie. Mirrors the value into
 *   $_COOKIE so a follow-up "check" in the same request sees it. */
if (!function_exists('sc_api_auth_set_cookie')) {
    function sc_api_auth_set_cookie($cfg, $user) {
        $params = sc_api_auth_cookie_params($cfg);
        $value  = sc_api_auth_cookie_value($cfg, $user);
        $expire = ($params['expires'] > 0) ? (time() + $params['expires']) : 0;
        /* PHP 5.2: name, value, expire, path, domain, secure, httponly */
        setcookie($params['name'], $value, $expire, '/', '', false, true);
        $_COOKIE[$params['name']] = $value;
    }
}

/* sc_api_auth_clear_cookie($cfg)
 *   Remove the session cookie by setting a past expiry. */
if (!function_exists('sc_api_auth_clear_cookie')) {
    function sc_api_auth_clear_cookie($cfg) {
        $params = sc_api_auth_cookie_params($cfg);
        setcookie($params['name'], '', time() - 3600, '/');
        if (isset($_COOKIE[$params['name']])) {
            unset($_COOKIE[$params['name']]);
        }
    }
}

/* sc_api_auth_cookie_context($cfg)
 *   Return the current authenticated user context, or ok=false. */
if (!function_exists('sc_api_auth_cookie_context')) {
    function sc_api_auth_cookie_context($cfg) {
        $params = sc_api_auth_cookie_params($cfg);
        if (!isset($_COOKIE[$params['name']])) {
            return array('ok' => false, 'username' => '');
        }
        $val = (string)$_COOKIE[$params['name']];
        if (function_exists('sc_auth_token_context')) {
            return sc_auth_token_context($val, $cfg);
        }
        if (strlen($val) < 6 || strpos($val, 'scv1:') !== 0) {
            return array('ok' => false, 'username' => '');
        }
        return array('ok' => true, 'username' => 'User');
    }
}

/* sc_api_auth_verify_cookie($cfg)
 *   Decide whether the current request carries a valid session cookie. */
if (!function_exists('sc_api_auth_verify_cookie')) {
    function sc_api_auth_verify_cookie($cfg) {
        $ctx = sc_api_auth_cookie_context($cfg);
        return !empty($ctx['ok']);
    }
}

/* sc_api_auth_read_body()
 *   Read and JSON-decode the current POST body. */
if (!function_exists('sc_api_auth_read_body')) {
    function sc_api_auth_read_body() {
        $raw = '';
        if (isset($_SERVER['REQUEST_METHOD'])
            && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST') {
            $raw = @file_get_contents('php://input');
        }
        if (!is_string($raw) || $raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        return $decoded;
    }
}

/* sc_api_auth_client_ip()
 *   Return the current client's IP (REMOTE_ADDR), or "0.0.0.0". */
if (!function_exists('sc_api_auth_client_ip')) {
    function sc_api_auth_client_ip() {
        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])
            && is_string($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        if ($ip === '') {
            $ip = '0.0.0.0';
        }
        return $ip;
    }
}

/* sc_api_auth_locked_until($ip, $cfg)
 *   Unix timestamp at which the IP's current lockout expires. */
if (!function_exists('sc_api_auth_locked_until')) {
    function sc_api_auth_locked_until($ip, $cfg) {
        $window = isset($cfg['auth']['lockout_seconds'])
                  ? (int)$cfg['auth']['lockout_seconds'] : 300;
        $cache = sc_auth_load_lockouts(
            sc_auth_lockout_path(), $window, time()
        );
        if (isset($cache[$ip]) && is_array($cache[$ip])
            && isset($cache[$ip]['ts'])) {
            return (int)$cache[$ip]['ts'] + $window;
        }
        return time() + $window;
    }
}

/* sc_api_auth_attempts_left($ip, $cfg)
 *   Number of remaining attempts before the client IP is locked. */
if (!function_exists('sc_api_auth_attempts_left')) {
    function sc_api_auth_attempts_left($ip, $cfg) {
        $max = isset($cfg['auth']['max_attempts'])
               ? (int)$cfg['auth']['max_attempts'] : 5;
        $window = isset($cfg['auth']['lockout_seconds'])
                  ? (int)$cfg['auth']['lockout_seconds'] : 300;
        $cache = sc_auth_load_lockouts(
            sc_auth_lockout_path(), $window, time()
        );
        $count = 0;
        if (isset($cache[$ip]) && is_array($cache[$ip])
            && isset($cache[$ip]['count'])) {
            $count = (int)$cache[$ip]['count'];
        }
        $left = $max - $count;
        if ($left < 0) {
            $left = 0;
        }
        return $left;
    }
}

/* sc_api_auth_set_status($status)
 *   Send a non-200 HTTP status line (PHP 5.2 lacks
 *   http_response_code()). */
if (!function_exists('sc_api_auth_set_status')) {
    function sc_api_auth_set_status($status) {
        if (headers_sent()) {
            return;
        }
        $code = (int)$status;
        $reasons = array(
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
        );
        $reason = isset($reasons[$code]) ? $reasons[$code] : '';
        $protocol = 'HTTP/1.0';
        if (isset($_SERVER['SERVER_PROTOCOL'])
            && is_string($_SERVER['SERVER_PROTOCOL'])
            && $_SERVER['SERVER_PROTOCOL'] !== '') {
            $protocol = $_SERVER['SERVER_PROTOCOL'];
        }
        if ($reason !== '') {
            header($protocol . ' ' . $code . ' ' . $reason);
        } else {
            header($protocol . ' ' . $code);
        }
    }
}

/* sc_api_auth_handle_login($cfg, $body)
 *   "login" action handler.
 *
 *   On locked IP: 429 + locked payload (no password check).
 *   On correct password: clear lockout counter, log success, set
 *   HttpOnly cookie, return ok+lang.
 *   On wrong password: increment counter, log failure, return
 *   invalid+attempts_left. */
if (!function_exists('sc_api_auth_handle_login')) {
    function sc_api_auth_handle_login($cfg, $body) {
        $password = '';
        if (isset($body['password']) && is_string($body['password'])) {
            $password = $body['password'];
        } elseif (isset($body['password'])) {
            /* tolerate non-string (e.g. number); cast without echo. */
            $password = (string)$body['password'];
        }
        $ip = sc_api_auth_client_ip();
        if (sc_auth_is_locked($ip, $cfg)) {
            sc_api_auth_set_status(429);
            return array(
                'ok'           => false,
                'error'        => 'locked',
                'locked'       => true,
                'locked_until' => sc_api_auth_locked_until($ip, $cfg),
            );
        }
        $login = sc_auth_login($password, $cfg);
        if (!empty($login['ok'])) {
            sc_api_auth_set_cookie($cfg, $login);
            $lang = sc_i18n_current_lang('en');
            if (function_exists('sc_auth_user_default_lang')
                && isset($login['username'])) {
                $lang = sc_auth_user_default_lang(
                    $cfg, (string)$login['username'], $lang
                );
            }
            return array(
                'ok'       => true,
                'lang'     => $lang,
                'username' => isset($login['username'])
                              ? (string)$login['username'] : 'User',
            );
        }
        if (isset($login['error']) && $login['error'] === 'locked') {
            sc_api_auth_set_status(429);
            return array(
                'ok'           => false,
                'error'        => 'locked',
                'locked'       => true,
                'locked_until' => sc_api_auth_locked_until($ip, $cfg),
            );
        }
        return array(
            'ok'            => false,
            'error'         => 'invalid',
            'attempts_left' => sc_api_auth_attempts_left($ip, $cfg),
        );
    }
}

/* sc_api_auth_handle_logout($cfg)
 *   "logout" action handler. Clears the session cookie. */
if (!function_exists('sc_api_auth_handle_logout')) {
    function sc_api_auth_handle_logout($cfg) {
        sc_api_auth_clear_cookie($cfg);
        return array('ok' => true);
    }
}

/* sc_api_auth_handle_check($cfg)
 *   "check" action handler. Returns the current session state. */
if (!function_exists('sc_api_auth_handle_check')) {
    function sc_api_auth_handle_check($cfg) {
        $ctx = sc_api_auth_cookie_context($cfg);
        if (!empty($ctx['ok'])) {
            return array(
                'ok'       => true,
                'lang'     => sc_i18n_current_lang('en'),
                'username' => isset($ctx['username'])
                              ? (string)$ctx['username'] : 'User',
            );
        }
        return array('ok' => false, 'error' => 'not_logged_in');
    }
}

/* sc_api_auth_dispatch()
 *   Single entry point: parse the body and route to the action
 *   handler. Defaults to "login" when the body has no action. */
if (!function_exists('sc_api_auth_dispatch')) {
    function sc_api_auth_dispatch() {
        $method = isset($_SERVER['REQUEST_METHOD'])
                  ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'POST') {
            return array('ok' => false, 'error' => 'method_not_allowed');
        }
        $cfg = sc_api_auth_load_cfg();
        if (!is_array($cfg) || empty($cfg)) {
            return array('ok' => false, 'error' => 'config_error');
        }
        $body   = sc_api_auth_read_body();
        $action = isset($body['action']) ? (string)$body['action'] : 'login';
        if ($action === 'login') {
            return sc_api_auth_handle_login($cfg, $body);
        }
        if ($action === 'logout') {
            return sc_api_auth_handle_logout($cfg);
        }
        if ($action === 'check') {
            return sc_api_auth_handle_check($cfg);
        }
        return array('ok' => false, 'error' => 'unknown_action');
    }
}

echo json_encode(sc_api_auth_dispatch());
