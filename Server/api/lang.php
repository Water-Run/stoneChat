<?php
/**
 * stoneChat Server API: language tables (public).
 *
 * Public endpoint at /Server/api/lang.php. No authentication required:
 * the translation tables themselves contain no secrets and are served
 * to every browser on first paint.
 *
 * Wire format:
 *   GET /Server/api/lang.php?lang=<code>
 *     <code>   - one of: zh-CN, zh-TW, en, ja, ko, ru, fr, de.
 *                Any other value (including non-strings such as
 *                ?lang[]=foo, or absent / empty) is rejected.
 *
 * Responses (always JSON; Content-Type: application/json; charset=UTF-8):
 *   200  { "ok": true,  "lang": "<code>", "entries": { ... } }
 *   400  { "ok": false, "error": "missing_lang"     }   ?lang= or absent
 *   400  { "ok": false, "error": "invalid_lang"     }   ?lang[]=... (non-string)
 *   404  { "ok": false, "error": "unsupported_lang" }   well-formed but not in list
 *   404  { "ok": false, "error": "not_found"        }   in list but file missing
 *   405  { "ok": false, "error": "method_not_allowed" } non-GET verb
 *
 * The entries hash is loaded from Server/langs/<code>.php via the
 * sc_i18n_load() helper, which accepts both `$entries = array(...)`
 * and `return array(...)` styles. Values are echoed as-is (UTF-8).
 *
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no
 * namespaces, no json_last_error, function_exists guards on every
 * helper).
 */

require_once dirname(__FILE__) . '/../boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
if (!function_exists('sc_i18n_load')) {
    require_once dirname(__FILE__) . '/../i18n.php';
}

if (!function_exists('sc_api_lang_status_text')) {
    /**
     * Map an HTTP status code to its RFC-2616 reason phrase.
     *
     * Limited to the small set this endpoint actually emits; an
     * unknown code returns an empty string so the caller can suppress
     * the reason phrase if it wishes.
     *
     * @param int $code Numeric HTTP status (e.g. 200, 400, 404, 405).
     * @return string   Reason phrase, or '' for unknown codes.
     */
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

if (!function_exists('sc_api_lang_set_status')) {
    /**
     * Send a non-default HTTP status line.
     *
     * PHP 5.2 lacks http_response_code(); we use the header() form
     * with the server-negotiated protocol prefix so the response
     * stays RFC-compatible.
     *
     * @param int $code Numeric HTTP status code.
     * @return void
     */
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

if (!function_exists('sc_api_lang_emit')) {
    /**
     * Emit a JSON response and terminate the request.
     *
     * Sets Content-Type, suppresses caching, and writes the encoded
     * body. Falls back to a static error payload if json_encode()
     * returns false (defensive: e.g. invalid UTF-8 input from a
     * malformed lang file).
     *
     * @param array $payload PHP value to encode.
     * @param int   $status  HTTP status code to send.
     * @return void
     */
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

if (!function_exists('sc_api_lang_normalize')) {
    /**
     * Trim and length-cap the requested lang code.
     *
     * Accepts only plain strings; arrays (e.g. ?lang[]=foo) are
     * rejected. Capping at 32 chars guards against absurdly long
     * values that would still be rejected by supported-langs lookup
     * but should not even reach sc_i18n_load().
     *
     * @param mixed $raw Raw $_GET['lang'] value.
     * @return array    array('ok'=>bool, 'lang'=>string, 'error'=>string)
     *                  - ok=true  => lang is a non-empty trimmed string <= 32 chars
     *                  - ok=false => lang is missing, non-string, or too long
     */
    function sc_api_lang_normalize($raw) {
        if (!is_string($raw)) {
            return array('ok' => false, 'lang' => '', 'error' => 'invalid_lang');
        }
        $lang = trim($raw);
        if ($lang === '') {
            return array('ok' => false, 'lang' => '', 'error' => 'missing_lang');
        }
        if (strlen($lang) > 32) {
            return array('ok' => false, 'lang' => '', 'error' => 'invalid_lang');
        }
        return array('ok' => true, 'lang' => $lang, 'error' => '');
    }
}

if (!function_exists('sc_api_lang_resolve')) {
    /**
     * Validate the lang code, load the table, and build the response.
     *
     * Pure: takes nothing from globals other than $_GET. The output
     * is a self-describing array that includes the HTTP status code
     * the caller should send; status is stripped before emission by
     * sc_api_lang_emit so it never reaches the client.
     *
     * @return array Response payload with a 'status' field for routing.
     */
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

if (!function_exists('sc_api_lang_dispatch')) {
    /**
     * Single entry point: route the request and return the payload.
     *
     * Defaults to GET semantics; non-GET verbs are rejected with
     * method_not_allowed (HTTP 405). The returned array carries the
     * HTTP status in a 'status' field that the caller must strip
     * before encoding the body.
     *
     * @return array Response payload (with an internal 'status' field).
     */
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

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------
$resp   = sc_api_lang_dispatch();
$status = isset($resp['status']) ? (int)$resp['status'] : 200;
unset($resp['status']);
sc_api_lang_emit($resp, $status);
