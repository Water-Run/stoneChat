<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/auth.php
 *
 * Password gate, brute-force lockout, and LOGIN.txt audit logging.
 *
 * Lockout state is persisted in HISTORY/.lockout_cache as a
 * pipe-delimited text file (one entry per line: ip|timestamp|count).
 * Per-IP counters auto-prune after lockout_seconds; the cache file
 * is rewritten on every update.
 *
 * LOGIN.txt is written to the project root, one line per attempt:
 *   [YYYY-MM-DD HH:MM:SS] [IP] [result] [user_agent]
 * Passwords are never written. The user agent is sanitized to a
 * single line to prevent log injection.
 *
 * Public helpers (sc_-prefixed, function_exists guarded):
 *   sc_auth_check_password($pw, $cfg)   verify password (constant-time)
 *   sc_auth_is_locked($ip, $cfg)        is the IP currently locked out?
 *   sc_auth_record_failure($ip, $cfg)   increment the IP's failure count
 *   sc_auth_record_success($ip)         clear the IP's failure count
 *   sc_auth_log_attempt($ip, $ok, $cfg) append a single audit line
 *   sc_auth_login($password, $cfg)      main entry
 *   sc_auth_log_login($cfg, $r, $ip)    log wrapper (textual result)
 *   sc_auth_reset_lock($cfg)            reset all lockouts
 *   sc_auth_clear_failures($cfg)        alias of reset_lock
 *   sc_auth_generate_token($cfg)        signed session token
 *   sc_auth_verify_token($token, $cfg)  verify a stateless token
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_auth_project_root()
 *   Absolute path of the project root (parent of Server/). */
if (!function_exists('sc_auth_project_root')) {
    function sc_auth_project_root() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..';
    }
}

/* sc_auth_lockout_path()
 *   Absolute path of HISTORY/.lockout_cache. */
if (!function_exists('sc_auth_lockout_path')) {
    function sc_auth_lockout_path() {
        $root = sc_auth_project_root();
        $dir  = $root . DIRECTORY_SEPARATOR . 'HISTORY';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . '.lockout_cache';
    }
}

/* sc_auth_login_log_path()
 *   Absolute path of LOGIN.txt in the project root. */
if (!function_exists('sc_auth_login_log_path')) {
    function sc_auth_login_log_path() {
        $root = sc_auth_project_root();
        return $root . DIRECTORY_SEPARATOR . 'LOGIN.txt';
    }
}

/* sc_auth_safe_eq($a, $b)
 *   Constant-time string compare (PHP 5.2 has no hash_equals).
 *   Iterates the longer length, OR-accumulates XOR of each byte
 *   (plus a length-difference bit); never short-circuits, so
 *   timing reveals nothing about which byte (or length) was wrong. */
if (!function_exists('sc_auth_safe_eq')) {
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

/* sc_auth_load_lockouts($path, $lockout_seconds, $now)
 *   Read the lockout cache into array(ip => array('ts', 'count')).
 *   Entries older than $lockout_seconds are pruned. Malformed
 *   lines and unreadable files yield an empty array. */
if (!function_exists('sc_auth_load_lockouts')) {
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

/* sc_auth_save_lockouts($path, $data)
 *   Persist the lockout cache atomically with LOCK_EX so two
 *   near-simultaneous failures do not lose updates. */
if (!function_exists('sc_auth_save_lockouts')) {
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

/* sc_auth_sanitize_log_value($s, $max_len)
 *   Sanitize a string for the login audit log: replace control
 *   chars (including \r, \n, \t) with spaces, truncate to
 *   $max_len. Prevents log-injection. */
if (!function_exists('sc_auth_sanitize_log_value')) {
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

/* sc_auth_check_password($password, $cfg)
 *   Constant-time compare against cfg[auth][password]. */
if (!function_exists('sc_auth_check_password')) {
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

/* sc_auth_is_locked($ip, $cfg)
 *   Is the IP currently locked out? An IP is locked iff its
 *   failure count is >= max_attempts AND its most-recent
 *   failure is still within the lockout window. */
if (!function_exists('sc_auth_is_locked')) {
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

/* sc_auth_record_failure($ip, $cfg)
 *   Increment the IP's failure counter and persist. */
if (!function_exists('sc_auth_record_failure')) {
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

/* sc_auth_record_success($ip)
 *   Clear the IP's failure counter. */
if (!function_exists('sc_auth_record_success')) {
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

/* sc_auth_log_attempt($ip, $ok, $cfg)
 *   Append a single audit line to LOGIN.txt.
 *
 *   Format: [YYYY-MM-DD HH:MM:SS] [IP] [result] [user_agent]
 *     result is "success" or "failure".
 *     user_agent is taken from $_SERVER['HTTP_USER_AGENT'] and
 *     sanitized to a single line; empty if the header is absent.
 *   No password is ever written. */
if (!function_exists('sc_auth_log_attempt')) {
    function sc_auth_log_attempt($ip, $ok, $cfg) {
        if (!is_string($ip) || $ip === '') {
            return false;
        }
        $path = sc_auth_login_log_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        /* file-permission check: refuse to write to a read-only log. */
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
        $line = '[' . $ts . '] [' . $ip . '] [' . $res . '] ['
              . $ua . ']' . "\n";
        $bytes = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        return ($bytes !== false);
    }
}

/* sc_auth_login($password, $cfg)
 *   Main entry: read client IP, gate on lockout, check password,
 *   record success/failure, log. */
if (!function_exists('sc_auth_login')) {
    function sc_auth_login($password, $cfg) {
        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])
            && is_string($_SERVER['REMOTE_ADDR'])) {
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

/* sc_auth_log_login($cfg, $result, $ip)
 *   Wrapper: textual $result -> bool, forwarded to log_attempt. */
if (!function_exists('sc_auth_log_login')) {
    function sc_auth_log_login($cfg, $result, $ip) {
        $ok = (is_string($result) && strtolower($result) === 'success');
        return sc_auth_log_attempt($ip, $ok, $cfg);
    }
}

/* sc_auth_reset_lock($cfg)
 *   Delete the lockout cache; intended for PHP startup. */
if (!function_exists('sc_auth_reset_lock')) {
    function sc_auth_reset_lock($cfg) {
        $path = sc_auth_lockout_path();
        if (!is_file($path)) {
            return true;
        }
        $ok = @unlink($path);
        return ($ok || !is_file($path));
    }
}

/* sc_auth_clear_failures($cfg)
 *   Alias of sc_auth_reset_lock. */
if (!function_exists('sc_auth_clear_failures')) {
    function sc_auth_clear_failures($cfg) {
        return sc_auth_reset_lock($cfg);
    }
}

/* sc_auth_generate_token($cfg)
 *   Build an opaque signed session token (prefix "scv1:"). */
if (!function_exists('sc_auth_generate_token')) {
    function sc_auth_generate_token($cfg) {
        $password = (is_array($cfg) && isset($cfg['auth']['password']))
                    ? (string)$cfg['auth']['password'] : '';
        $ts = time();
        return 'scv1:' . $ts . ':' . md5($ts . '|' . $password);
    }
}

/* sc_auth_verify_token($token, $cfg)
 *   Verify a stateless signed token. */
if (!function_exists('sc_auth_verify_token')) {
    function sc_auth_verify_token($token, $cfg) {
        if (!is_string($token) || strlen($token) < 6
            || strpos($token, 'scv1:') !== 0) {
            return false;
        }
        $parts = explode(':', $token);
        if (count($parts) !== 3) {
            return false;
        }
        $ts = $parts[1];
        $sig = $parts[2];
        $password = (is_array($cfg) && isset($cfg['auth']['password']))
                    ? (string)$cfg['auth']['password'] : '';
        $expected = md5($ts . '|' . $password);
        return sc_auth_safe_eq($sig, $expected);
    }
}
