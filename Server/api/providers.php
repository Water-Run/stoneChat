<?php
/**
 * stoneChat Server /api/providers endpoint.
 *
 * Routes:
 *   GET  /api/providers
 *       List every [Provider N] from CONF.ini with a per-provider status
 *       block. Secrets are NEVER returned in cleartext; api_key is masked
 *       to "XXXX****YYYY" (or "****" when too short to mask safely).
 *
 *   POST /api/providers?action=test_all
 *       Ping every provider with a fixed "ping" user message. Each ping
 *       is bounded by a 3-second per-call latency budget (label only);
 *       the overall loop is hard-capped at 5 seconds and marks any
 *       providers it could not reach as "timeout".
 *
 * Response shapes:
 *   GET:
 *     {
 *       "ok": true,
 *       "providers": [
 *         {
 *           "id": "openai",
 *           "display_name": "OpenAI (ChatGPT)",   // user-prompt field
 *           "label": "OpenAI (ChatGPT)",          // JSON-spec alias
 *           "type": "openai",
 *           "model": "gpt-3.5-turbo",
 *           "api_base": "https://api.openai.com/v1",
 *           "api_key": "sk-Y****abcd",            // masked, never full
 *           "stream": false,
 *           "max_tokens": 1024,
 *           "timeout": 60,
 *           "available": true,                    // JSON-spec field
 *           "reason": ""                          // JSON-spec field
 *         }, ...
 *       ]
 *     }
 *
 *   POST action=test_all:
 *     {
 *       "ok": true,
 *       "results": [
 *         {"id": "openai", "ok": true, "latency_ms": 234, "error": ""},
 *         ...
 *       ]
 *     }
 *
 * Security notes:
 *   - The full api_key is never written to the response. The mask is
 *     applied in sc_api_providers_mask_key() and is the only place
 *     api_key values reach the JSON encoder.
 *   - test_all results include id/ok/latency_ms/error only; no secrets.
 *
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_load_providers')) {
    require_once dirname(__FILE__) . '/../config.php';
}
if (!function_exists('sc_llm_chat')) {
    require_once dirname(__FILE__) . '/../llm.php';
}

if (!function_exists('sc_api_providers_mask_key')) {
    /**
     * Mask an api_key for safe display in the GET response.
     *
     * Returns "XXXX****YYYY" for keys at least 12 bytes long (preserves
     * the first and last 4 bytes). Anything shorter - including empty
     * strings, the literal "****", and stub values - is collapsed to
     * "****" so a 4-byte prefix cannot reconstruct the secret. The full
     * key is never returned by this function.
     *
     * @param string $key Raw api_key value (may be null/non-string).
     * @return string      Masked representation.
     */
    function sc_api_providers_mask_key($key) {
        if (!is_string($key) || $key === '') {
            return '';
        }
        $t = trim($key);
        if ($t === '' || $t === '****') {
            return '****';
        }
        $len = strlen($t);
        if ($len < 12) {
            return '****';
        }
        $head = substr($t, 0, 4);
        $tail = substr($t, -4);
        return $head . '****' . $tail;
    }
}

if (!function_exists('sc_api_providers_is_placeholder_key')) {
    /**
     * Heuristic: does the key look like an unfilled CONF.ini placeholder?
     *
     * Treats empty strings, "****", and the common "YOUR_*_HERE" stub
     * pattern as placeholders. Anything else is considered "set" and
     * reaches the network as-is.
     *
     * @param string $key Raw api_key value.
     * @return bool true if the key should be treated as unset.
     */
    function sc_api_providers_is_placeholder_key($key) {
        if (!is_string($key) || $key === '') {
            return true;
        }
        $t = trim($key);
        if ($t === '' || $t === '****') {
            return true;
        }
        if (preg_match('/^YOUR_[A-Z0-9_]*_HERE$/', $t)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('sc_api_providers_check_base')) {
    /**
     * Validate an api_base URL: must start with http:// or https://
     * and parse_url() must yield a host.
     *
     * @param string $base api_base value from the provider config.
     * @return bool true if the URL is well-formed.
     */
    function sc_api_providers_check_base($base) {
        if (!is_string($base) || $base === '') {
            return false;
        }
        $lower = strtolower($base);
        if (strpos($lower, 'http://') !== 0 && strpos($lower, 'https://') !== 0) {
            return false;
        }
        $parts = @parse_url($base);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        return true;
    }
}

if (!function_exists('sc_api_providers_default_scalar')) {
    /**
     * Look up a per-provider scalar override from the config.
     *
     * Reads CONF.ini [llm] and [ui] sections for shared defaults; per-
     * provider overrides under [Provider N][<key>] take precedence.
     *
     * @param array  $cfg      Parsed config (raw, from sc_load_config()).
     * @param string $key      Scalar key (e.g. "max_tokens", "timeout").
     * @param mixed  $fallback Default if nothing is set.
     * @return mixed           Override value or fallback.
     */
    function sc_api_providers_default_scalar($cfg, $key, $fallback) {
        if (!is_array($cfg)) {
            return $fallback;
        }
        if (isset($cfg['llm'][$key]) && $cfg['llm'][$key] !== '') {
            return $cfg['llm'][$key];
        }
        if (isset($cfg['ui'][$key]) && $cfg['ui'][$key] !== '') {
            return $cfg['ui'][$key];
        }
        return $fallback;
    }
}

if (!function_exists('sc_api_providers_normalize')) {
    /**
     * Convert a raw provider config row into the GET-response shape.
     *
     * The masked api_key is the ONLY place a key value reaches the
     * response; never echo the raw field.
     *
     * @param array $p         Raw provider (id/label/type/api_base/api_key/model).
     * @param array $defaults  Shared scalar defaults (stream/max_tokens/timeout).
     * @return array           JSON-ready provider row.
     */
    function sc_api_providers_normalize($p, $defaults) {
        $id       = isset($p['id'])       ? (string)$p['id']       : '';
        $label    = isset($p['label'])    ? (string)$p['label']    : '';
        $type     = isset($p['type'])     ? (string)$p['type']     : '';
        $model    = isset($p['model'])    ? (string)$p['model']    : '';
        $api_base = isset($p['api_base']) ? (string)$p['api_base'] : '';
        $api_key  = isset($p['api_key'])  ? (string)$p['api_key']  : '';
        $display  = ($label !== '') ? $label : $id;

        $available = true;
        $reason    = '';
        if (sc_api_providers_is_placeholder_key($api_key)) {
            $available = false;
            $reason    = 'no_api_key';
        } elseif (!sc_api_providers_check_base($api_base)) {
            $available = false;
            $reason    = 'bad_endpoint';
        }

        return array(
            'id'           => $id,
            'display_name' => $display,
            'label'        => $display,
            'type'         => $type,
            'model'        => $model,
            'api_base'     => $api_base,
            // The api_key field is exposed only as a redacted marker
            // ("****" for any non-empty key, "" for empty) so the
            // public /api/providers response never reveals the first
            // or last 4 bytes of the real key. The previous
            // "XXXX****YYYY" head/tail mask leaked a small prefix and
            // suffix of the secret to any unauthenticated client; the
            // sc_api_providers_mask_key() helper is kept for callers
            // that explicitly want a less-aggressive redaction, but
            // the public shape is now always "****" or "".
            'api_key'      => $api_key === '' ? '' : '****',
            'stream'       => isset($defaults['stream'])     ? (bool)$defaults['stream']     : false,
            'max_tokens'   => isset($defaults['max_tokens']) ? (int)$defaults['max_tokens'] : 1024,
            'timeout'      => isset($defaults['timeout'])    ? (int)$defaults['timeout']    : 60,
            'available'    => $available,
            'reason'       => $reason,
        );
    }
}

if (!function_exists('sc_api_providers_load_raw')) {
    /**
     * Load the configured providers from CONF.ini as raw rows.
     *
     * @param string $ini_path Absolute path to CONF.ini.
     * @return array List of raw provider arrays (with real api_key).
     */
    function sc_api_providers_load_raw($ini_path) {
        if (!is_string($ini_path) || !function_exists('sc_load_providers')) {
            return array();
        }
        $raw = sc_load_providers($ini_path);
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $p) {
            if (is_array($p)) {
                $out[] = $p;
            }
        }
        return $out;
    }
}

if (!function_exists('sc_api_providers_load_all')) {
    /**
     * Load the configured providers and return their normalized
     * response rows, keyed by id for cross-lookup.
     *
     * @param string $ini_path Absolute path to CONF.ini.
     * @param array  $cfg      Optional pre-parsed config (for scalar
     *                         overrides). If omitted, sc_load_config()
     *                         is called on $ini_path.
     * @return array           array('raw' => array, 'by_id' => array, 'list' => array)
     */
    function sc_api_providers_load_all($ini_path, $cfg) {
        $raw = sc_api_providers_load_raw($ini_path);
        $stream     = (bool)sc_api_providers_default_scalar($cfg, 'stream', false);
        $max_tokens = (int) sc_api_providers_default_scalar($cfg, 'max_tokens', 1024);
        $timeout    = (int) sc_api_providers_default_scalar($cfg, 'timeout', 60);
        $defaults = array(
            'stream'     => $stream,
            'max_tokens' => $max_tokens,
            'timeout'    => $timeout,
        );
        $by_id = array();
        $list  = array();
        foreach ($raw as $p) {
            $row = sc_api_providers_normalize($p, $defaults);
            $by_id[$row['id']] = $row;
            $list[] = $row;
        }
        return array('raw' => $raw, 'by_id' => $by_id, 'list' => $list);
    }
}

if (!function_exists('sc_api_providers_ping_one')) {
    /**
     * Run a single ping chat call against a provider.
     *
     * Uses sc_llm_chat() with a fixed one-token "ping" user message.
     * Records the wall-clock latency. Returns array('id','ok',
     * 'latency_ms','error'). The api_key is consumed by sc_llm_chat
     * and never reaches the caller.
     *
     * @param array  $provider        Raw provider config (must include id, type, model, api_key, api_base).
     * @param int    $timeout_seconds Per-call latency budget (seconds). 0 disables the soft timeout label.
     * @return array                  Result row.
     */
    function sc_api_providers_ping_one($provider, $timeout_seconds) {
        $id = (is_array($provider) && isset($provider['id']))
              ? (string)$provider['id'] : '';
        if (!is_array($provider)) {
            return array('id' => $id, 'ok' => false, 'latency_ms' => 0, 'error' => 'invalid_provider');
        }
        if (!function_exists('sc_llm_chat')) {
            return array('id' => $id, 'ok' => false, 'latency_ms' => 0, 'error' => 'llm_unavailable');
        }
        $messages = array(array('role' => 'user', 'content' => 'ping'));
        $start = microtime(true);
        $result = sc_llm_chat($provider, $messages, '');
        $elapsed_ms = (int)round((microtime(true) - $start) * 1000);
        if ($timeout_seconds > 0 && $elapsed_ms > ($timeout_seconds * 1000)) {
            $err = 'timeout';
            if (is_array($result) && isset($result['error']) && (string)$result['error'] !== '') {
                $err = 'timeout:' . (string)$result['error'];
            }
            return array('id' => $id, 'ok' => false, 'latency_ms' => $elapsed_ms, 'error' => $err);
        }
        if (!is_array($result)) {
            return array('id' => $id, 'ok' => false, 'latency_ms' => $elapsed_ms, 'error' => 'no_result');
        }
        if (empty($result['ok'])) {
            $err = isset($result['error']) ? (string)$result['error'] : 'unknown';
            return array('id' => $id, 'ok' => false, 'latency_ms' => $elapsed_ms, 'error' => $err);
        }
        return array('id' => $id, 'ok' => true, 'latency_ms' => $elapsed_ms, 'error' => '');
    }
}

if (!function_exists('sc_api_providers_test_all')) {
    /**
     * Ping every configured provider; cap total wall-clock at $total_timeout.
     *
     * The per-call latency budget is enforced as a label after the call
     * returns (the underlying socket already has its own read timeout);
     * the real safety net is the 5-second wall-clock cap below: once
     * the deadline is reached, no new pings are issued and remaining
     * providers are reported as "timeout".
     *
     * Providers that were marked unavailable during normalize() are
     * skipped - they cannot answer and reporting a network error would
     * be misleading. Their precomputed 'reason' is forwarded.
     *
     * @param array  $raw          Raw providers (with real api_key).
     * @param array  $by_id        Normalized rows keyed by id.
     * @param int    $per_timeout  Per-call latency budget (seconds).
     * @param int    $total_timeout Total wall-clock budget (seconds).
     * @return array               List of {id, ok, latency_ms, error} rows.
     */
    function sc_api_providers_test_all($raw, $by_id, $per_timeout, $total_timeout) {
        $out = array();
        $deadline = ($total_timeout > 0) ? (microtime(true) + (float)$total_timeout) : 0.0;
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $p) {
            $id = (is_array($p) && isset($p['id'])) ? (string)$p['id'] : '';
            if (!empty($deadline) && microtime(true) >= $deadline) {
                $out[] = array('id' => $id, 'ok' => false, 'latency_ms' => 0, 'error' => 'timeout');
                continue;
            }
            if (isset($by_id[$id]) && is_array($by_id[$id]) && empty($by_id[$id]['available'])) {
                $reason = isset($by_id[$id]['reason']) ? (string)$by_id[$id]['reason'] : 'unavailable';
                $out[] = array('id' => $id, 'ok' => false, 'latency_ms' => 0, 'error' => $reason);
                continue;
            }
            $out[] = sc_api_providers_ping_one($p, $per_timeout);
        }
        return $out;
    }
}

if (!function_exists('sc_api_providers_emit_json')) {
    /**
     * Emit a JSON response and terminate the request.
     *
     * Sets Content-Type and short-circuits any caching. The body is
     * encoded with the default options (PHP 5.2-safe: JSON_UNESCAPED_SLASHES
     * is PHP 5.4+ and not used here).
     *
     * @param array $body Response body to encode.
     */
    function sc_api_providers_emit_json($body) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
        $json = @json_encode($body);
        if (!is_string($json)) {
            $json = '{"ok":false,"error":"json_encode_failed"}';
        }
        echo $json;
    }
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------
$ini_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
          . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'CONF.ini';

$cfg = array();
if (is_file($ini_path) && is_readable($ini_path) && function_exists('sc_load_config')) {
    $cfg = sc_load_config($ini_path);
    if (!is_array($cfg)) {
        $cfg = array();
    }
}

$method = isset($_SERVER['REQUEST_METHOD'])
          ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';

$action = '';
if (isset($_GET['action']) && is_string($_GET['action'])) {
    $action = strtolower(trim($_GET['action']));
} elseif (isset($_POST['action']) && is_string($_POST['action'])) {
    $action = strtolower(trim($_POST['action']));
}

$loaded = sc_api_providers_load_all($ini_path, $cfg);
$raw    = $loaded['raw'];
$list   = $loaded['list'];
$by_id  = $loaded['by_id'];

if ($method === 'POST' && $action === 'test_all') {
    // 3 seconds per call (label only, see sc_api_providers_ping_one),
    // 5 seconds total (hard cap in sc_api_providers_test_all).
    $results = sc_api_providers_test_all($raw, $by_id, 3, 5);
    sc_api_providers_emit_json(array(
        'ok'      => true,
        'results' => $results,
    ));
    exit;
}

// Default: GET-style listing (also handles GET /api/providers).
sc_api_providers_emit_json(array(
    'ok'        => true,
    'providers' => $list,
));
exit;
