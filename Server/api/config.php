<?php
/**
 * stoneChat Server api/config endpoint.
 *
 * Public endpoint served at /api/config. Two request shapes are supported:
 *   GET                -> sanitized public config for the client. The payload
 *                        contains ONLY non-sensitive fields:
 *                          title         (string, from [ui].title)
 *                          default_lang  (string, from [ui].default_lang)
 *                          theme         (string, from [ui].theme)
 *                          providers     (array of {id,label,type,model})
 *                          langs         (array of supported language codes)
 *                          auth_enabled  (bool, from [auth].enabled with a
 *                                        password-set fallback)
 *                        Provider api_key / api_base and [auth].password are
 *                        NEVER echoed back.
 *   POST reload_config -> {ok:true, message:'reloaded'}; re-reads CONF.ini.
 *
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no namespaces,
 * no json_last_error).
 */

require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../i18n.php';

if (!function_exists('sc_api_config_ini_path')) {
    /**
     * Resolve the absolute path to CONF.ini from this file's location.
     *
     * Layout: <root>/Server/api/config.php  ->  <root>/CONF.ini.
     *
     * @return string Absolute path to CONF.ini.
     */
    function sc_api_config_ini_path() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
             . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'CONF.ini';
    }
}

if (!function_exists('sc_api_config_sanitize_providers')) {
    /**
     * Strip every sensitive field from a provider list, leaving only
     * client-safe metadata.
     *
     * Source arrays come from sc_load_providers() and contain
     * id / label / type / api_base / api_key / model. Only id, label, type,
     * model survive; api_key and api_base are dropped here.
     *
     * @param array $providers Raw provider list (may be empty / non-array).
     * @return array List of sanitized provider entries.
     */
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

if (!function_exists('sc_api_config_truthy')) {
    /**
     * Parse an INI-style boolean value.
     *
     * Accepts the common truthy spellings (1, true, yes, on) and the common
     * falsy spellings (0, false, no, off). Empty / unknown values return null
     * so callers can decide on a fallback.
     *
     * @param mixed $value Raw value from parse_ini_file().
     * @return bool|null   true / false on a recognized flag, null otherwise.
     */
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

if (!function_exists('sc_api_config_resolve_auth_enabled')) {
    /**
     * Decide whether authentication is enabled from the parsed config.
     *
     * Honors an explicit [auth].enabled flag (truthy / falsy strings).
     * Falls back to "enabled iff a non-empty password is configured" so a
     * freshly installed CONF.ini with a placeholder password still reports
     * accurately. The password value itself never leaves this function.
     *
     * @param array $cfg Parsed config from sc_load_config().
     * @return bool
     */
    function sc_api_config_resolve_auth_enabled($cfg) {
        if (!is_array($cfg) || !isset($cfg['auth']) || !is_array($cfg['auth'])) {
            return false;
        }
        if (isset($cfg['auth']['enabled'])) {
            $flag = sc_api_config_truthy($cfg['auth']['enabled']);
            if ($flag !== null) {
                return $flag;
            }
        }
        // Fallback: a non-empty password implies auth is in use.
        if (isset($cfg['auth']['password'])
            && (string)$cfg['auth']['password'] !== '') {
            return true;
        }
        return false;
    }
}

if (!function_exists('sc_api_config_build_payload')) {
    /**
     * Build the GET /api/config response payload from CONF.ini.
     *
     * Reads CONF.ini via sc_load_config() and sc_load_providers(), then
     * constructs a sanitized array. Sensitive fields are dropped here, so
     * the rest of the file can echo the payload verbatim without further
     * filtering.
     *
     * @return array Sanitized public config payload.
     */
    function sc_api_config_build_payload() {
        $path = sc_api_config_ini_path();
        $cfg = sc_load_config($path);
        if (!is_array($cfg)) {
            $cfg = array();
        }
        // Pull each visible field with a safe default; never propagate
        // unexpected scalar types into the JSON payload.
        $title = 'stoneChat';
        if (isset($cfg['ui']['title']) && (string)$cfg['ui']['title'] !== '') {
            $title = (string)$cfg['ui']['title'];
        }
        $default_lang = 'en';
        if (isset($cfg['ui']['default_lang'])
            && (string)$cfg['ui']['default_lang'] !== '') {
            $default_lang = (string)$cfg['ui']['default_lang'];
        }
        $theme = 'classic2001';
        if (isset($cfg['ui']['theme']) && (string)$cfg['ui']['theme'] !== '') {
            $theme = (string)$cfg['ui']['theme'];
        }
        return array(
            'title'        => $title,
            'default_lang' => $default_lang,
            'theme'        => $theme,
            'providers'    => sc_api_config_sanitize_providers(
                                  sc_load_providers($path)
                              ),
            'langs'        => sc_i18n_supported_langs(),
            'auth_enabled' => sc_api_config_resolve_auth_enabled($cfg),
        );
    }
}

if (!function_exists('sc_api_config_reload')) {
    /**
     * Re-read CONF.ini from disk and report success.
     *
     * parse_ini_file() does not cache; the next sc_load_config() call will
     * see the fresh file automatically. We only verify readability here so
     * the client gets a clear error if the file has been moved.
     *
     * @return array {ok:bool, message?:string, error?:string}
     */
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

if (!function_exists('sc_api_config_emit')) {
    /**
     * Send a PHP value as a JSON response with the project-mandated header.
     *
     * Falls back to a static error payload if json_encode() returns false
     * (older PHP behavior, possible on invalid UTF-8 strings); the client
     * still gets valid JSON in either case.
     *
     * @param mixed $payload PHP value to encode.
     * @return void
     */
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

if (!function_exists('sc_api_config_dispatch')) {
    /**
     * Route the current HTTP request to the right handler.
     *
     * Returns the value to emit; the caller is responsible for output. Kept
     * separate from the include-and-emit block below so the same routing
     * logic could be unit-tested by including this file in isolation.
     *
     * @return array Response payload.
     */
    function sc_api_config_dispatch() {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper((string)$_SERVER['REQUEST_METHOD'])
            : 'GET';
        if ($method === 'POST') {
            $action = '';
            // Prefer ?action=... so a normal HTML form could trigger it too.
            if (isset($_GET['action']) && is_string($_GET['action'])) {
                $action = strtolower((string)$_GET['action']);
            }
            // Accept {"action":"..."} in the JSON body as well.
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
            if ($action === 'reload_config') {
                return sc_api_config_reload();
            }
            return array('ok' => false, 'error' => 'unknown_action');
        }
        // GET (and any other verb) returns the public config.
        return sc_api_config_build_payload();
    }
}

// --- Entry point -----------------------------------------------------------
sc_api_config_emit(sc_api_config_dispatch());