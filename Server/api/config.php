<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/api/config.php
 *
 * Public endpoint served at /api/config. Two request shapes:
 *
 *   GET                  -> sanitized public config. The payload
 *                          contains ONLY non-sensitive fields:
 *                            title         (from [ui].title)
 *                            default_lang  (from [ui].default_lang)
 *                            theme         (from [ui].theme)
 *                            providers     (list of {id,label,type,model})
 *                            langs         (supported language codes)
 *                            auth_enabled  (bool)
 *                          Provider api_key / api_base and
 *                          [auth].password are NEVER echoed back.
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
 *   Strip every sensitive field from a provider list, keeping only
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
 *   [auth].enabled flag, otherwise falls back to "enabled iff a
 *   real (non-placeholder) password is configured" so a freshly
 *   installed CONF.ini reports auth_enabled=false accurately. */
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
        /* fallback: a non-empty, non-placeholder password implies auth. */
        if (isset($cfg['auth']['password'])
            && (string)$cfg['auth']['password'] !== '') {
            $raw = (string)$cfg['auth']['password'];
            if (function_exists('sc_is_placeholder_password')
                && sc_is_placeholder_password($raw)) {
                return false;
            }
            return true;
        }
        return false;
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
        return array(
            'title'               => $title,
            'default_lang'        => $default_lang,
            'theme'               => $theme,
            'allow_online_editor' => $allow_online_editor,
            'providers'           => sc_api_config_sanitize_providers(
                                         sc_load_providers($path)
                                     ),
            'langs'               => sc_i18n_supported_langs(),
            'auth_enabled'        => sc_api_config_resolve_auth_enabled($cfg),
        );
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
            /* prefer ?action=... so a normal HTML form could
             * trigger it too. */
            if (isset($_GET['action']) && is_string($_GET['action'])) {
                $action = strtolower((string)$_GET['action']);
            }
            /* accept {"action":"..."} in the JSON body as well. */
            if ($action === '') {
                $raw = @file_get_contents('php://input');
                if (is_string($raw) && $raw !== '') {
                    $body = json_decode($raw, true);
                    if (is_array($body) && isset($body['action'])
                        && is_string($body['action'])) {
                        $action = strtolower((string)$body['action']);
                    }
                }
            }
            if ($action === 'reload_config' || $action === 'reload') {
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
