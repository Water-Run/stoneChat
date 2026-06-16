<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/api/lang.php
 *
 * Public endpoint at /Server/api/lang.php. No authentication required:
 * the translation tables contain no secrets and are served to every
 * browser on first paint.
 *
 * Wire format:
 *   GET /Server/api/lang.php?lang=<code>
 *     <code>   - one of: zh-CN, zh-TW, en, ja, ko, ru, fr, de.
 *                Any other value (including non-strings such as
 *                ?lang[]=foo, or absent / empty) is rejected.
 *
 * Responses (always JSON; Content-Type: application/json; charset=UTF-8):
 *   200  { "ok": true,  "lang": "<code>", "entries": { ... } }
 *   400  { "ok": false, "error": "missing_lang"     }
 *   400  { "ok": false, "error": "invalid_lang"     }
 *   404  { "ok": false, "error": "unsupported_lang" }
 *   404  { "ok": false, "error": "not_found"        }
 *   405  { "ok": false, "error": "method_not_allowed" }
 *
 * The entries hash is loaded from Server/langs/<code>.php via
 * sc_i18n_load(). Values are echoed as-is (UTF-8).
 *
 * PHP 5.2 compatible (no closures, no [] array syntax, no namespaces,
 * no json_last_error, function_exists guards on every helper).
 * ------------------------------------------------------------------------- */

require_once dirname(__FILE__) . '/../boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
if (!function_exists('sc_i18n_load')) {
    require_once dirname(__FILE__) . '/../i18n.php';
}

/* sc_api_lang_status_text($code)
 *   Map an HTTP status code to its RFC-2616 reason phrase. */
if (!function_exists('sc_api_lang_status_text')) {
    function sc_api_lang_status_text($code) {
        $code = (int)$code;
        $reasons = array(
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
        );
        return isset($reasons[$code]) ? $reasons[$code] : '';
    }
}

/* sc_api_lang_set_status($code)
 *   Send a non-default HTTP status line. PHP 5.2 lacks
 *   http_response_code(); use header() with the server's
 *   negotiated protocol prefix. */
if (!function_exists('sc_api_lang_set_status')) {
    function sc_api_lang_set_status($code) {
        if (headers_sent()) {
            return;
        }
        $code = (int)$code;
        $protocol = 'HTTP/1.0';
        if (isset($_SERVER['SERVER_PROTOCOL'])
            && is_string($_SERVER['SERVER_PROTOCOL'])
            && $_SERVER['SERVER_PROTOCOL'] !== '') {
            $protocol = $_SERVER['SERVER_PROTOCOL'];
        }
        $reason = sc_api_lang_status_text($code);
        if ($reason !== '') {
            header($protocol . ' ' . $code . ' ' . $reason);
        } else {
            header($protocol . ' ' . $code);
        }
    }
}

/* sc_api_lang_emit($payload, $status)
 *   Emit a JSON response and terminate the request. */
if (!function_exists('sc_api_lang_emit')) {
    function sc_api_lang_emit($payload, $status) {
        sc_api_lang_set_status($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
        $json = json_encode($payload);
        if (!is_string($json)) {
            $json = '{"ok":false,"error":"json_encode_failed"}';
        }
        echo $json;
    }
}

/* sc_api_lang_normalize($raw)
 *   Trim and length-cap the requested lang code. Rejects arrays
 *   (e.g. ?lang[]=foo); caps at 32 chars. */
if (!function_exists('sc_api_lang_normalize')) {
    function sc_api_lang_normalize($raw) {
        if (!is_string($raw)) {
            return array('ok' => false, 'lang' => '',
                         'error' => 'invalid_lang');
        }
        $lang = trim($raw);
        if ($lang === '') {
            return array('ok' => false, 'lang' => '',
                         'error' => 'missing_lang');
        }
        if (strlen($lang) > 32) {
            return array('ok' => false, 'lang' => '',
                         'error' => 'invalid_lang');
        }
        return array('ok' => true, 'lang' => $lang, 'error' => '');
    }
}

/* sc_api_lang_resolve()
 *   Validate the lang code, load the table, build the response. */
if (!function_exists('sc_api_lang_resolve')) {
    function sc_api_lang_resolve() {
        $raw = isset($_GET['lang']) ? $_GET['lang'] : '';
        $norm = sc_api_lang_normalize($raw);
        if (empty($norm['ok'])) {
            return array(
                'ok'     => false,
                'error'  => $norm['error'],
                'status' => 400,
            );
        }
        $lang = $norm['lang'];
        if (!in_array($lang, sc_i18n_supported_langs(), true)) {
            return array(
                'ok'     => false,
                'error'  => 'unsupported_lang',
                'status' => 404,
            );
        }
        $entries = sc_i18n_load($lang);
        if (!is_array($entries) || count($entries) === 0) {
            return array(
                'ok'     => false,
                'error'  => 'not_found',
                'status' => 404,
            );
        }
        return array(
            'ok'      => true,
            'lang'    => $lang,
            'entries' => $entries,
            'status'  => 200,
        );
    }
}

/* sc_api_lang_dispatch()
 *   Single entry point: route the request and return the payload. */
if (!function_exists('sc_api_lang_dispatch')) {
    function sc_api_lang_dispatch() {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper((string)$_SERVER['REQUEST_METHOD'])
            : 'GET';
        if ($method !== 'GET') {
            return array(
                'ok'     => false,
                'error'  => 'method_not_allowed',
                'status' => 405,
            );
        }
        return sc_api_lang_resolve();
    }
}

/* ---- entry point ------------------------------------------------- */
$resp   = sc_api_lang_dispatch();
$status = isset($resp['status']) ? (int)$resp['status'] : 200;
unset($resp['status']);
sc_api_lang_emit($resp, $status);
