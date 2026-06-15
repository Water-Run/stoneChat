<?php
/**
 * stoneChat Server API: auth.
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
 *   login success     { "ok": true,  "lang": "<code>" }
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
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no
 * json_last_error, function_exists guards on every helper).
 */

// Dependencies: load via relative path from this file's directory
// (Server/api/auth.php -> Server/ and back to project root for config).
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../auth.php';
require_once dirname(__FILE__) . '/../i18n.php';

header('Content-Type: application/json; charset=UTF-8');

if (!function_exists('sc_api_auth_cfg_path')) {
    /**
     * Absolute path to CONF.ini (project root).
     *
     * @return string Path.
     */
    function sc_api_auth_cfg_path() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR
             . '..' . DIRECTORY_SEPARATOR
             . '..' . DIRECTORY_SEPARATOR
             . 'CONF.ini';
    }
}

if (!function_exists('sc_api_auth_load_cfg')) {
    /**
     * Load the parsed stoneChat config.
     *
     * @return array Parsed config, or empty array on failure.
     */
    function sc_api_auth_load_cfg() {
        return sc_load_config(sc_api_auth_cfg_path());
    }
}

if (!function_exists('sc_api_auth_cookie_params')) {
    /**
     * Resolve the session cookie's name and lifetime from cfg.
     *
     * @param array $cfg Parsed config.
     * @return array array('name' => string, 'expires' => int seconds, 0 = session).
     */
    function sc_api_auth_cookie_params($cfg) {
        $name    = 'sc_auth';
        $expires = 0;
        if (is_array($cfg) && isset($cfg['auth']) && is_array($cfg['auth'])) {
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

if (!function_exists('sc_api_auth_cookie_value')) {
    /**
     * Build an opaque session cookie value.
     *
     * For LAN deployment with a shared password the value need only
     * be non-empty and unguessable; we never include the password
     * (or any derivative of it) in the value.
     *
     * @return string Opaque value with a fixed "scv1:" prefix.
     */
    function sc_api_auth_cookie_value() {
        $r1 = function_exists('mt_rand') ? mt_rand() : rand();
        $r2 = function_exists('mt_rand') ? mt_rand() : rand();
        $r3 = function_exists('mt_rand') ? mt_rand() : rand();
        return 'scv1:' . md5($r1 . '|' . $r2 . '|' . $r3 . '|' . time());
    }
}

if (!function_exists('sc_api_auth_set_cookie')) {
    /**
     * Issue the HttpOnly session cookie.
     *
     * Also mirrors the value into $_COOKIE so a follow-up "check"
     * in the same request sees the new state.
     *
     * @param array $cfg Parsed config.
     */
    function sc_api_auth_set_cookie($cfg) {
        $params = sc_api_auth_cookie_params($cfg);
        $value  = sc_api_auth_cookie_value();
        $expire = ($params['expires'] > 0) ? (time() + $params['expires']) : 0;
        // PHP 5.2: name, value, expire, path, domain, secure, httponly
        setcookie($params['name'], $value, $expire, '/', '', false, true);
        $_COOKIE[$params['name']] = $value;
    }
}

if (!function_exists('sc_api_auth_clear_cookie')) {
    /**
     * Remove the session cookie by setting a past expiry.
     *
     * @param array $cfg Parsed config.
     */
    function sc_api_auth_clear_cookie($cfg) {
        $params = sc_api_auth_cookie_params($cfg);
        setcookie($params['name'], '', time() - 3600, '/');
        if (isset($_COOKIE[$params['name']])) {
            unset($_COOKIE[$params['name']]);
        }
    }
}

if (!function_exists('sc_api_auth_verify_cookie')) {
    /**
     * Decide whether the current request carries a valid session cookie.
     *
     * The check is format-only (we have no server-side session store);
     * the LAN threat model treats the cookie as a capability token.
     *
     * @param array $cfg Parsed config.
     * @return bool true if a well-formed session cookie is present.
     */
    function sc_api_auth_verify_cookie($cfg) {
        $params = sc_api_auth_cookie_params($cfg);
        if (!isset($_COOKIE[$params['name']])) {
            return false;
        }
        $val = (string)$_COOKIE[$params['name']];
        if (strlen($val) < 6 || strpos($val, 'scv1:') !== 0) {
            return false;
        }
        return true;
    }
}

if (!function_exists('sc_api_auth_read_body')) {
    /**
     * Read and JSON-decode the current POST body.
     *
     * @return array Decoded body, or empty array on no body / parse error.
     */
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

if (!function_exists('sc_api_auth_client_ip')) {
    /**
     * Return the current client's IP (REMOTE_ADDR), or "0.0.0.0".
     *
     * @return string IP string.
     */
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

if (!function_exists('sc_api_auth_locked_until')) {
    /**
     * Unix timestamp at which the IP's current lockout expires.
     *
     * Computed from the last-failure timestamp recorded in the
     * lockout cache plus the configured lockout window.
     *
     * @param string $ip  Client IP.
     * @param array  $cfg Parsed config.
     * @return int Unix timestamp.
     */
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

if (!function_exists('sc_api_auth_attempts_left')) {
    /**
     * Number of remaining attempts before the client IP is locked.
     *
     * @param string $ip  Client IP.
     * @param array  $cfg Parsed config.
     * @return int Non-negative attempt count.
     */
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

if (!function_exists('sc_api_auth_set_status')) {
    /**
     * Send a non-200 HTTP status line.
     *
     * PHP 5.2 lacks http_response_code(); we use the header() form
     * with the server's negotiated protocol prefix instead.
     *
     * @param int $status Numeric HTTP status code (e.g. 429).
     */
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

if (!function_exists('sc_api_auth_handle_login')) {
    /**
     * "login" action handler.
     *
     * On locked IP: 429 + locked payload (no password check).
     * On correct password: clear lockout counter, log success,
     * set HttpOnly cookie, return ok+lang.
     * On wrong password: increment lockout counter, log failure,
     * return invalid+attempts_left.
     *
     * @param array $cfg  Parsed config.
     * @param array $body Decoded request body.
     * @return array Response payload.
     */
    function sc_api_auth_handle_login($cfg, $body) {
        $password = '';
        if (isset($body['password']) && is_string($body['password'])) {
            $password = $body['password'];
        } elseif (isset($body['password'])) {
            // Tolerate non-string (e.g. number); cast without echo.
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
        if (sc_auth_check_password($password, $cfg)) {
            sc_auth_record_success($ip);
            sc_auth_log_attempt($ip, true, $cfg);
            sc_api_auth_set_cookie($cfg);
            return array(
                'ok'   => true,
                'lang' => sc_i18n_current_lang('en'),
            );
        }
        sc_auth_record_failure($ip, $cfg);
        sc_auth_log_attempt($ip, false, $cfg);
        return array(
            'ok'            => false,
            'error'         => 'invalid',
            'attempts_left' => sc_api_auth_attempts_left($ip, $cfg),
        );
    }
}

if (!function_exists('sc_api_auth_handle_logout')) {
    /**
     * "logout" action handler. Clears the session cookie.
     *
     * @param array $cfg Parsed config (unused; reserved).
     * @return array {ok: true}
     */
    function sc_api_auth_handle_logout($cfg) {
        sc_api_auth_clear_cookie($cfg);
        return array('ok' => true);
    }
}

if (!function_exists('sc_api_auth_handle_check')) {
    /**
     * "check" action handler. Returns the current session state.
     *
     * @param array $cfg Parsed config.
     * @return array ok+lang when authenticated; not_logged_in otherwise.
     */
    function sc_api_auth_handle_check($cfg) {
        if (sc_api_auth_verify_cookie($cfg)) {
            return array(
                'ok'   => true,
                'lang' => sc_i18n_current_lang('en'),
            );
        }
        return array('ok' => false, 'error' => 'not_logged_in');
    }
}

if (!function_exists('sc_api_auth_dispatch')) {
    /**
     * Single entry point: parse the body and route to the action handler.
     *
     * Defaults to "login" when the body has no action field, so a
     * client that posts just {"password": "..."} works as expected.
     * Non-POST requests get method_not_allowed.
     *
     * @return array Response payload (echoed as JSON by the caller).
     */
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
