<?php
/**
 * stoneChat Server auth module.
 *
 * Password gate, brute-force lockout, and LOGIN.txt audit logging.
 *
 * Lockout state is persisted in HISTORY/.lockout_cache as a
 * pipe-delimited text file (one entry per line: ip|timestamp|count).
 * Per-IP counters auto-prune after lockout_seconds; the cache file
 * is rewritten on every update.
 *
 * LOGIN.txt is written to the project root with one line per
 * attempt in the format:
 *   [YYYY-MM-DD HH:MM:SS] [IP] [result] [user_agent]
 * Passwords are never written to the log; the user agent is
 * sanitized to a single line to prevent log injection.
 *
 * Public functions (all sc_-prefixed, each wrapped in a function_exists
 * guard):
 *   sc_auth_check_password($password, $cfg)
 *   sc_auth_is_locked($ip, $cfg)
 *   sc_auth_record_failure($ip, $cfg)
 *   sc_auth_record_success($ip)
 *   sc_auth_log_attempt($ip, $ok, $cfg)
 *   sc_auth_login($password, $cfg)         - main entry
 *   sc_auth_log_login($cfg, $result, $ip)  - log wrapper
 *   sc_auth_reset_lock($cfg)               - reset all lockouts
 *   sc_auth_clear_failures($cfg)           - alias of reset_lock
 *
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_auth_project_root')) {
    /**
     * Absolute path of the project root (parent of Server/).
     *
     * @return string Absolute path with trailing separator.
     */
    function sc_auth_project_root() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..';
    }
}

if (!function_exists('sc_auth_lockout_path')) {
    /**
     * Absolute path of the lockout cache file.
     *
     * Lives in HISTORY/.lockout_cache. The HISTORY directory is
     * auto-created on first use.
     *
     * @return string Absolute path.
     */
    function sc_auth_lockout_path() {
        $root = sc_auth_project_root();
        $dir  = $root . DIRECTORY_SEPARATOR . 'HISTORY';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . '.lockout_cache';
    }
}

if (!function_exists('sc_auth_login_log_path')) {
    /**
     * Absolute path of the LOGIN.txt audit log.
     *
     * Located in the project root so users can find it easily.
     *
     * @return string Absolute path.
     */
    function sc_auth_login_log_path() {
        $root = sc_auth_project_root();
        return $root . DIRECTORY_SEPARATOR . 'LOGIN.txt';
    }
}

if (!function_exists('sc_auth_safe_eq')) {
    /**
     * Constant-time string comparison.
     *
     * PHP 5.2 lacks hash_equals(), so we hand-roll one. The function
     * iterates over the longer length and OR-accumulates the XOR of
     * each byte (plus a length-difference bit). It never short-
     * circuits, so timing reveals nothing about which byte (or which
     * length) was wrong.
     *
     * @param string $a First string.
     * @param string $b Second string.
     * @return bool true iff both strings are byte-identical.
     */
    function sc_auth_safe_eq($a, $b) {
        if (!is_string($a) || !is_string($b)) {
            return false;
        }
        $la = strlen($a);
        $lb = strlen($b);
        $diff = ($la === $lb) ? 0 : 1;
        $max  = ($la > $lb) ? $la : $lb;
        for ($i = 0; $i < $max; $i++) {
            $ca = ($i < $la) ? ord($a[$i]) : 0;
            $cb = ($i < $lb) ? ord($b[$i]) : 0;
            $diff |= ($ca ^ $cb);
        }
        return ($diff === 0);
    }
}

if (!function_exists('sc_auth_load_lockouts')) {
    /**
     * Read the lockout cache into an associative array.
     *
     * Each entry is array('ts' => int, 'count' => int). Entries
     * whose timestamp is older than $lockout_seconds are pruned.
     * Malformed lines and unreadable files yield an empty array.
     *
     * @param string $path            Cache file path.
     * @param int    $lockout_seconds Window during which failures count.
     * @param int    $now             Current Unix timestamp.
     * @return array                  array(ip => array('ts', 'count')).
     */
    function sc_auth_load_lockouts($path, $lockout_seconds, $now) {
        $out = array();
        if (!is_string($path) || $path === '') {
            return $out;
        }
        if (!is_file($path) || !is_readable($path)) {
            return $out;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return $out;
        }
        $window = (int)$lockout_seconds;
        $now_i  = (int)$now;
        foreach ($lines as $line) {
            $parts = explode('|', $line);
            if (count($parts) < 3) {
                continue;
            }
            $ip    = (string)$parts[0];
            $ts    = (int)$parts[1];
            $count = (int)$parts[2];
            if ($ip === '' || $count <= 0) {
                continue;
            }
            if (($now_i - $ts) > $window) {
                continue;
            }
            $out[$ip] = array('ts' => $ts, 'count' => $count);
        }
        return $out;
    }
}

if (!function_exists('sc_auth_save_lockouts')) {
    /**
     * Persist the lockout cache atomically.
     *
     * Uses LOCK_EX so two near-simultaneous failures do not lose
     * updates. The format is preserved (one entry per line).
     *
     * @param string $path Cache file path.
     * @param array  $data array(ip => array('ts', 'count')).
     * @return bool true on success.
     */
    function sc_auth_save_lockouts($path, $data) {
        if (!is_string($path) || $path === '') {
            return false;
        }
        if (!is_array($data)) {
            return false;
        }
        $lines = array();
        foreach ($data as $ip => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ip_s  = (string)$ip;
            $ts    = isset($entry['ts']) ? (int)$entry['ts'] : time();
            $count = isset($entry['count']) ? (int)$entry['count'] : 0;
            if ($ip_s === '' || $count <= 0) {
                continue;
            }
            $lines[] = $ip_s . '|' . $ts . '|' . $count;
        }
        $body = implode("\n", $lines);
        if ($body !== '') {
            $body .= "\n";
        }
        $bytes = @file_put_contents($path, $body, LOCK_EX);
        return ($bytes !== false);
    }
}

if (!function_exists('sc_auth_sanitize_log_value')) {
    /**
     * Sanitize a string before it is written to the login audit log.
     *
     * Replaces control characters (including \r, \n, \t) with spaces
     * and truncates to a maximum length. This prevents log injection
     * (an attacker forging extra log lines) and keeps the format
     * stable.
     *
     * @param string $s       Raw value (may be null/non-string).
     * @param int    $max_len Maximum length; 0 disables truncation.
     * @return string         Sanitized value (empty string on bad input).
     */
    function sc_auth_sanitize_log_value($s, $max_len) {
        if (!is_string($s)) {
            return '';
        }
        $s = str_replace(array("\r", "\n", "\t"), ' ', $s);
        $cleaned = @preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        if (!is_string($cleaned)) {
            $cleaned = '';
        }
        $max = (int)$max_len;
        if ($max > 0 && strlen($cleaned) > $max) {
            $cleaned = substr($cleaned, 0, $max);
        }
        return $cleaned;
    }
}

if (!function_exists('sc_auth_check_password')) {
    /**
     * Verify a password against cfg[auth][password].
     *
     * Comparison is constant-time (see sc_auth_safe_eq). Returns
     * false on missing or wrong-typed arguments so callers can pass
     * user input directly.
     *
     * @param string $password User-supplied password.
     * @param array  $cfg      Parsed config from sc_load_config().
     * @return bool true iff the password matches.
     */
    function sc_auth_check_password($password, $cfg) {
        if (!is_string($password) || !is_array($cfg)) {
            return false;
        }
        $expected = '';
        if (isset($cfg['auth']) && is_array($cfg['auth'])
            && isset($cfg['auth']['password'])) {
            $expected = (string)$cfg['auth']['password'];
        }
        return sc_auth_safe_eq($password, $expected);
    }
}

if (!function_exists('sc_auth_is_locked')) {
    /**
     * Determine whether the given IP is currently locked out.
     *
     * An IP is locked iff its failure count is >= max_attempts AND
     * its most-recent failure is still within the lockout window.
     * If max_attempts or lockout_seconds is missing/non-positive
     * the function returns false (lockout disabled).
     *
     * @param string $ip  Client IP.
     * @param array  $cfg Parsed config from sc_load_config().
     * @return bool true if locked out.
     */
    function sc_auth_is_locked($ip, $cfg) {
        if (!is_string($ip) || $ip === '' || !is_array($cfg)) {
            return false;
        }
        $max = isset($cfg['auth']['max_attempts'])
               ? (int)$cfg['auth']['max_attempts'] : 0;
        $window = isset($cfg['auth']['lockout_seconds'])
                  ? (int)$cfg['auth']['lockout_seconds'] : 0;
        if ($max <= 0 || $window <= 0) {
            return false;
        }
        $data = sc_auth_load_lockouts(sc_auth_lockout_path(), $window, time());
        if (!isset($data[$ip]) || !is_array($data[$ip])) {
            return false;
        }
        $count = isset($data[$ip]['count']) ? (int)$data[$ip]['count'] : 0;
        return ($count >= $max);
    }
}

if (!function_exists('sc_auth_record_failure')) {
    /**
     * Increment the failure counter for an IP and persist it.
     *
     * If the IP has no entry yet, one is created. The lockout
     * window is applied so stale entries for OTHER IPs are pruned
     * (bounded file size).
     *
     * @param string $ip  Client IP.
     * @param array  $cfg Parsed config from sc_load_config().
     * @return bool true on success.
     */
    function sc_auth_record_failure($ip, $cfg) {
        if (!is_string($ip) || $ip === '' || !is_array($cfg)) {
            return false;
        }
        $window = isset($cfg['auth']['lockout_seconds'])
                  ? (int)$cfg['auth']['lockout_seconds'] : 300;
        $path = sc_auth_lockout_path();
        $now  = time();
        $data = sc_auth_load_lockouts($path, $window, $now);
        $cur  = 0;
        if (isset($data[$ip]) && is_array($data[$ip])
            && isset($data[$ip]['count'])) {
            $cur = (int)$data[$ip]['count'];
        }
        $data[$ip] = array('ts' => $now, 'count' => $cur + 1);
        return sc_auth_save_lockouts($path, $data);
    }
}

if (!function_exists('sc_auth_record_success')) {
    /**
     * Clear the failure counter for a single IP.
     *
     * A long window is used when reading so that other IPs' entries
     * (whose original timestamps are preserved on save) are not
     * pruned as a side effect. They will be pruned naturally on
     * the next sc_auth_record_failure / sc_auth_is_locked call.
     *
     * @param string $ip Client IP.
     * @return bool true on success (including when the IP had no entry).
     */
    function sc_auth_record_success($ip) {
        if (!is_string($ip) || $ip === '') {
            return false;
        }
        $path = sc_auth_lockout_path();
        $data = sc_auth_load_lockouts($path, 86400 * 365, time());
        if (!isset($data[$ip])) {
            return true;
        }
        unset($data[$ip]);
        return sc_auth_save_lockouts($path, $data);
    }
}

if (!function_exists('sc_auth_log_attempt')) {
    /**
     * Append a single audit line to LOGIN.txt.
     *
     * Format: [YYYY-MM-DD HH:MM:SS] [IP] [result] [user_agent]
     *   result is "success" or "failure".
     *   user_agent is taken from $_SERVER['HTTP_USER_AGENT'] and
     *   sanitized to a single line; empty if the header is absent.
     * No password is ever written.
     *
     * If LOGIN.txt already exists and is not writable, the function
     * returns false without modifying the file.
     *
     * @param string $ip  Client IP (will be sanitized before writing).
     * @param bool   $ok  true for success, false for failure.
     * @param array  $cfg Parsed config (currently unused; reserved).
     * @return bool true if a line was written.
     */
    function sc_auth_log_attempt($ip, $ok, $cfg) {
        if (!is_string($ip) || $ip === '') {
            return false;
        }
        $path = sc_auth_login_log_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // File permissions check: refuse to write to a read-only log.
        if (is_file($path) && !is_writable($path)) {
            return false;
        }
        $ts  = date('Y-m-d H:i:s');
        $ip  = sc_auth_sanitize_log_value($ip, 64);
        $res = $ok ? 'success' : 'failure';
        $ua  = '';
        if (isset($_SERVER['HTTP_USER_AGENT'])
            && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $ua = $_SERVER['HTTP_USER_AGENT'];
        }
        $ua  = sc_auth_sanitize_log_value($ua, 200);
        $line = '[' . $ts . '] [' . $ip . '] [' . $res . '] [' . $ua . ']' . "\n";
        $bytes = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        return ($bytes !== false);
    }
}

if (!function_exists('sc_auth_login')) {
    /**
     * Main entry point: attempt to authenticate a login.
     *
     * Reads the client IP from $_SERVER['REMOTE_ADDR']. If the IP
     * is already locked out, returns array('ok' => false,
     * 'error' => 'locked') without touching the counter. Otherwise
     * compares the password and either records a success (clearing
     * the counter) or a failure (incrementing it). The result is
     * also written to LOGIN.txt.
     *
     * @param string $password User-supplied password.
     * @param array  $cfg      Parsed config from sc_load_config().
     * @return array           array('ok' => bool, 'error' => string).
     *                         'error' is empty on success and one
     *                         of 'locked', 'wrong_password' on
     *                         failure.
     */
    function sc_auth_login($password, $cfg) {
        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        if ($ip === '') {
            $ip = '0.0.0.0';
        }
        if (sc_auth_is_locked($ip, $cfg)) {
            sc_auth_log_attempt($ip, false, $cfg);
            return array('ok' => false, 'error' => 'locked');
        }
        if (sc_auth_check_password($password, $cfg)) {
            sc_auth_record_success($ip);
            sc_auth_log_attempt($ip, true, $cfg);
            return array('ok' => true, 'error' => '');
        }
        sc_auth_record_failure($ip, $cfg);
        sc_auth_log_attempt($ip, false, $cfg);
        return array('ok' => false, 'error' => 'wrong_password');
    }
}

if (!function_exists('sc_auth_log_login')) {
    /**
     * Convenience wrapper around sc_auth_log_attempt().
     *
     * Accepts a textual $result ("success", "failure", "locked",
     * etc.) and maps it to a boolean. Unrecognized values are
     * treated as failure so the log never silently omits events.
     *
     * @param array  $cfg    Parsed config (forwarded).
     * @param string $result Textual result code.
     * @param string $ip     Client IP.
     * @return bool true if a line was written.
     */
    function sc_auth_log_login($cfg, $result, $ip) {
        $ok = (is_string($result) && strtolower($result) === 'success');
        return sc_auth_log_attempt($ip, $ok, $cfg);
    }
}

if (!function_exists('sc_auth_reset_lock')) {
    /**
     * Reset the global lockout state by deleting the cache file.
     *
     * Intended to be called at PHP startup (e.g. from RUN.bat)
     * to satisfy the "restart clears lockout" deployment policy.
     * Returns true if the file is gone afterwards (whether it
     * existed or not).
     *
     * @param array $cfg Parsed config (currently unused).
     * @return bool true on success.
     */
    function sc_auth_reset_lock($cfg) {
        $path = sc_auth_lockout_path();
        if (!is_file($path)) {
            return true;
        }
        $ok = @unlink($path);
        return ($ok || !is_file($path));
    }
}

if (!function_exists('sc_auth_clear_failures')) {
    /**
     * Alias of sc_auth_reset_lock().
     *
     * Provided so call sites using the "clear failures" vocabulary
     * (admin actions) work without depending on the internal
     * "lock" naming.
     *
     * @param array $cfg Parsed config (forwarded).
     * @return bool true on success.
     */
    function sc_auth_clear_failures($cfg) {
        return sc_auth_reset_lock($cfg);
    }
}
