<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/api/config.php
 *
 * Public endpoint served at /api/config. Two request shapes:
 *
 *   GET                  -> sanitized public config. The payload
 *                          contains ONLY non-sensitive fields:
 *                            title         (from [ui].title)
 *                            default_lang  (current user, or [ui] fallback)
 *                            theme         (from [ui].theme)
 *                            providers     (list of {id,label,type,model})
 *                            langs         (supported language codes)
 *                            auth_enabled  (bool)
 *                          Model api_key / api_base and
 *                          [User NAME].password are NEVER echoed back.
 *   POST reload_config   -> {ok:true, message:'reloaded'}; re-reads
 *                          CONF.ini.
 *
 * PHP 5.2 compatible (no closures, no [] array syntax, no namespaces,
 * no json_last_error).
 * ------------------------------------------------------------------------- */

require_once dirname(__FILE__) . '/../boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../auth.php';
require_once dirname(__FILE__) . '/../i18n.php';

/* sc_api_config_ini_path()
 *   Absolute path to CONF.ini (two levels up from this file). */
if (!function_exists('sc_api_config_ini_path')) {
    function sc_api_config_ini_path() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
             . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'CONF.ini';
    }
}

/* sc_api_config_sanitize_providers($providers)
 *   Strip every sensitive field from a model list, keeping only
 *   client-safe metadata: id, label, type, model. */
if (!function_exists('sc_api_config_sanitize_providers')) {
    function sc_api_config_sanitize_providers($providers) {
        $out = array();
        if (!is_array($providers)) {
            return $out;
        }
        foreach ($providers as $p) {
            if (!is_array($p)) {
                continue;
            }
            $out[] = array(
                'id'    => isset($p['id'])    ? (string)$p['id']    : '',
                'label' => isset($p['label']) ? (string)$p['label'] : '',
                'type'  => isset($p['type'])  ? (string)$p['type']  : '',
                'model' => isset($p['model']) ? (string)$p['model'] : '',
            );
        }
        return $out;
    }
}

/* sc_api_config_truthy($value)
 *   Parse an INI-style boolean. Accepts the common truthy spellings
 *   (1, true, yes, on) and falsy spellings (0, false, no, off);
 *   empty / unknown values return null. */
if (!function_exists('sc_api_config_truthy')) {
    function sc_api_config_truthy($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $v = strtolower(trim($value));
        if ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on') {
            return true;
        }
        if ($v === '0' || $v === 'false' || $v === 'no' || $v === 'off') {
            return false;
        }
        return null;
    }
}

/* sc_api_config_resolve_auth_enabled($cfg)
 *   Decide whether authentication is enabled. Honours an explicit
 *   [auth].enabled flag, otherwise falls back to "enabled iff
 *   at least one [User NAME] exists". */
if (!function_exists('sc_api_config_resolve_auth_enabled')) {
    function sc_api_config_resolve_auth_enabled($cfg) {
        if (!is_array($cfg) || !isset($cfg['auth'])
            || !is_array($cfg['auth'])) {
            return false;
        }
        if (isset($cfg['auth']['enabled'])) {
            $flag = sc_api_config_truthy($cfg['auth']['enabled']);
            if ($flag !== null) {
                return $flag;
            }
        }
        if (function_exists('sc_auth_has_config_users')
            && sc_auth_has_config_users($cfg)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('sc_api_config_cookie_context')) {
    function sc_api_config_cookie_context($cfg) {
        $name = 'sc_auth';
        if (is_array($cfg) && isset($cfg['auth']['cookie_name'])
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
        if ($token !== '' && function_exists('sc_auth_token_context')) {
            return sc_auth_token_context($token, $cfg);
        }
        return array('ok' => false, 'username' => '');
    }
}

if (!function_exists('sc_api_config_add_csrf')) {
    function sc_api_config_add_csrf($cfg, $payload) {
        if (!is_array($payload)) {
            $payload = array();
        }
        $session_token = '';
        if (function_exists('sc_auth_session_token_from_cookie')) {
            $session_token = sc_auth_session_token_from_cookie($cfg);
        }
        if ($session_token !== '' && function_exists('sc_auth_csrf_tokens')) {
            $payload['csrf'] = sc_auth_csrf_tokens(
                $session_token,
                array('config:reload')
            );
        }
        return $payload;
    }
}

if (!function_exists('sc_api_config_read_body')) {
    function sc_api_config_read_body() {
        $raw = @file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $body = json_decode($raw, true);
            if (is_array($body)) {
                return $body;
            }
        }
        if (isset($_POST) && is_array($_POST) && !empty($_POST)) {
            return $_POST;
        }
        return array();
    }
}

if (!function_exists('sc_api_config_set_status')) {
    function sc_api_config_set_status($status) {
        if (headers_sent()) {
            return;
        }
        $reasons = array(403 => 'Forbidden');
        $code = (int)$status;
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

if (!function_exists('sc_api_config_require_csrf')) {
    function sc_api_config_require_csrf($cfg, $body) {
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
                && sc_auth_csrf_verify($session_token, 'config:reload',
                                       $posted));
    }
}

if (!function_exists('sc_api_config_runtime_info')) {
    function sc_api_config_runtime_info() {
        $timezone = '';
        if (function_exists('date_default_timezone_get')) {
            $timezone = @date_default_timezone_get();
        }
        return array(
            'modern_windows' => function_exists('sc_is_modern_windows')
                                ? sc_is_modern_windows() : false,
            'php_version'    => PHP_VERSION,
            'os'             => PHP_OS,
            'timezone'       => $timezone,
        );
    }
}

/* sc_api_config_build_payload()
 *   Build the GET /api/config response payload. */
if (!function_exists('sc_api_config_build_payload')) {
    function sc_api_config_build_payload() {
        $path = sc_api_config_ini_path();
        $cfg = sc_load_config($path);
        if (!is_array($cfg)) {
            $cfg = array();
        }
        /* pull each visible field with a safe default; never
         * propagate unexpected scalar types into the JSON payload. */
        $title = 'stoneChat';
        if (isset($cfg['ui']['title'])
            && (string)$cfg['ui']['title'] !== '') {
            $title = (string)$cfg['ui']['title'];
        }
        $default_lang = 'en';
        if (isset($cfg['ui']['default_lang'])
            && (string)$cfg['ui']['default_lang'] !== '') {
            $default_lang = (string)$cfg['ui']['default_lang'];
        }
        $theme = 'classic2001';
        if (isset($cfg['ui']['theme'])
            && (string)$cfg['ui']['theme'] !== '') {
            $theme = (string)$cfg['ui']['theme'];
        }
        $allow_online_editor = false;
        if (isset($cfg['ui']['allow_online_editor'])) {
            $flag = sc_api_config_truthy($cfg['ui']['allow_online_editor']);
            if ($flag !== null) {
                $allow_online_editor = $flag;
            }
        }
        $ctx = sc_api_config_cookie_context($cfg);
        $username = '';
        $can_edit_config = false;
        $send_shortcut = 'enter';
        if (!empty($ctx['ok'])) {
            $username = isset($ctx['username']) ? (string)$ctx['username'] : '';
            if (function_exists('sc_auth_can_edit_config')) {
                $can_edit_config = sc_auth_can_edit_config($cfg, $username);
            }
            if (function_exists('sc_auth_user_send_shortcut')) {
                $send_shortcut = sc_auth_user_send_shortcut($cfg, $username);
            }
            if (function_exists('sc_auth_user_default_lang')) {
                $default_lang = sc_auth_user_default_lang(
                    $cfg, $username, $default_lang
                );
            }
        }
        /* Model list must honour per-user excluded_models (same as
         * /api/providers and chat dispatch). Otherwise a restricted
         * account can still see forbidden model ids in the public config. */
        $providers = array();
        if (function_exists('sc_load_providers')) {
            $providers = sc_load_providers($path);
        }
        if (!is_array($providers)) {
            $providers = array();
        }
        if ($username !== '' && function_exists('sc_auth_filter_providers')) {
            $providers = sc_auth_filter_providers($providers, $cfg, $username);
        }

        return sc_api_config_add_csrf($cfg, array(
            'title'               => $title,
            'default_lang'        => $default_lang,
            'theme'               => $theme,
            'allow_online_editor' => $allow_online_editor,
            'can_edit_config'     => ($allow_online_editor && $can_edit_config),
            'send_shortcut'       => $send_shortcut,
            'username'            => $username,
            'providers'           => sc_api_config_sanitize_providers($providers),
            'langs'               => sc_i18n_supported_langs(),
            'auth_enabled'        => sc_api_config_resolve_auth_enabled($cfg),
            'runtime'             => sc_api_config_runtime_info(),
        ));
    }
}

/* sc_api_config_reload()
 *   Re-read CONF.ini from disk and report success. */
if (!function_exists('sc_api_config_reload')) {
    function sc_api_config_reload() {
        $path = sc_api_config_ini_path();
        if (!is_file($path) || !is_readable($path)) {
            return array('ok' => false, 'error' => 'config_not_readable');
        }
        $cfg = sc_load_config($path);
        if (!is_array($cfg)) {
            return array('ok' => false, 'error' => 'config_parse_failed');
        }
        return array('ok' => true, 'message' => 'reloaded');
    }
}

/* sc_api_config_emit($payload)
 *   Send a PHP value as a JSON response with the project-mandated
 *   header. Falls back to a static error payload on encode failure. */
if (!function_exists('sc_api_config_emit')) {
    function sc_api_config_emit($payload) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: Sat, 1 Jan 2000 00:00:00 GMT');
        }
        $json = json_encode($payload);
        if ($json === false) {
            $json = '{"ok":false,"error":"json_encode_failed"}';
        }
        echo $json;
    }
}

/* sc_api_config_dispatch()
 *   Route the current HTTP request to the right handler. */
if (!function_exists('sc_api_config_dispatch')) {
    function sc_api_config_dispatch() {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper((string)$_SERVER['REQUEST_METHOD'])
            : 'GET';
        if ($method === 'POST') {
            $action = '';
            $body = array();
            /* prefer ?action=... so a normal HTML form could
             * trigger it too. */
            if (isset($_GET['action']) && is_string($_GET['action'])) {
                $action = strtolower((string)$_GET['action']);
            }
            /* accept {"action":"..."} in the JSON body as well. */
            if ($action === '') {
                $body = sc_api_config_read_body();
                if (is_array($body) && isset($body['action'])
                    && is_string($body['action'])) {
                    $action = strtolower((string)$body['action']);
                }
            }
            if ($action === 'reload_config' || $action === 'reload') {
                if (!is_array($body) || empty($body)) {
                    $body = sc_api_config_read_body();
                }
                $cfg = sc_load_config(sc_api_config_ini_path());
                if (!sc_api_config_require_csrf($cfg, $body)) {
                    sc_api_config_set_status(403);
                    return array('ok' => false, 'error' => 'csrf_required');
                }
                return sc_api_config_reload();
            }
            return array('ok' => false, 'error' => 'unknown_action');
        }
        /* GET (and any other verb) returns the public config. */
        return sc_api_config_build_payload();
    }
}

/* ---- entry point ------------------------------------------------- */
sc_api_config_emit(sc_api_config_dispatch());
