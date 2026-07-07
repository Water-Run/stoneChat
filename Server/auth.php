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
 *   sc_auth_login_user($pw, $cfg)       resolve username by password
 *   sc_auth_token_context($token,$cfg)  verify token and return user context
 *   sc_auth_provider_allowed(...)       provider access by user entry
 *   sc_auth_is_locked($ip, $cfg)        is the IP currently locked out?
 *   sc_auth_record_failure($ip, $cfg)   increment the IP's failure count
 *   sc_auth_record_success($ip)         clear the IP's failure count
 *   sc_auth_log_attempt($ip, $ok, $cfg) append a single audit line
 *   sc_auth_login($password, $cfg)      main entry
 *   sc_auth_log_login($cfg, $r, $ip)    log wrapper (textual result)
 *   sc_auth_reset_lock($cfg)            reset all lockouts
 *   sc_auth_clear_failures($cfg)        alias of reset_lock
 *   sc_auth_generate_token($cfg,$user) signed session token
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

/* sc_auth_truthy($value, $fallback)
 *   Old INI files store booleans as text. Keep parsing tiny and explicit. */
if (!function_exists('sc_auth_truthy')) {
    function sc_auth_truthy($value, $fallback) {
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string)$value));
        if ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on') {
            return true;
        }
        if ($v === '0' || $v === 'false' || $v === 'no' || $v === 'off') {
            return false;
        }
        return (bool)$fallback;
    }
}

/* sc_auth_apply_timezone($cfg)
 *   auth.php is sometimes loaded by command-line checks without
 *   boot_check.php. Set the log clock here too, using the project's
 *   old Windows local default when PHP has no useful default. */
if (!function_exists('sc_auth_apply_timezone')) {
    function sc_auth_apply_timezone($cfg) {
        if (!function_exists('date_default_timezone_set')) {
            return;
        }
        $tz = '';
        if (function_exists('ini_get')) {
            $tz = (string)@ini_get('date.timezone');
        }
        if ($tz === '' || strtolower($tz) === 'utc') {
            $tz = 'Asia/Shanghai';
        }
        @date_default_timezone_set($tz);
    }
}

/* sc_auth_config_users($cfg)
 *   Read [User NAME] sections. NAME is the login/display username.
 *   Rights live directly under the same section. */
if (!function_exists('sc_auth_config_users')) {
    function sc_auth_config_users($cfg) {
        $out = array();
        if (!is_array($cfg)) {
            return $out;
        }
        foreach ($cfg as $section => $row) {
            if (!is_string($section) || !is_array($row)) {
                continue;
            }
            if (!preg_match('/^User[ \t]+(.+)$/i', $section, $m)) {
                continue;
            }
            $username = trim((string)$m[1]);
            $password = isset($row['password']) ? (string)$row['password'] : '';
            $active   = isset($row['active'])
                        ? sc_auth_truthy($row['active'], false) : false;
            $can_edit_config = isset($row['can_edit_config'])
                               ? sc_auth_truthy($row['can_edit_config'], false)
                               : false;
            $excluded_models = isset($row['excluded_models'])
                               ? trim((string)$row['excluded_models']) : '';
            $send_shortcut = isset($row['send_shortcut'])
                             ? strtolower(trim((string)$row['send_shortcut']))
                             : 'enter';
            if ($send_shortcut !== 'shift_enter') {
                $send_shortcut = 'enter';
            }
            $default_lang = isset($row['default_lang'])
                            ? trim((string)$row['default_lang']) : '';
            if ($default_lang === '') {
                $default_lang = 'en';
            }
            $out[] = array(
                'username'        => $username,
                'password'        => $password,
                'active'          => $active,
                'can_edit_config' => $can_edit_config,
                'excluded_models' => $excluded_models,
                'send_shortcut'   => $send_shortcut,
                'default_lang'    => $default_lang,
                'section'         => $section,
            );
        }
        return $out;
    }
}

if (!function_exists('sc_auth_has_config_users')) {
    function sc_auth_has_config_users($cfg) {
        $users = sc_auth_config_users($cfg);
        return !empty($users);
    }
}

/* sc_auth_find_user_by_password($password, $cfg)
 *   Match the password to a configured [User NAME]. No old shared-password
 *   fallback: users are now the only login model. */
if (!function_exists('sc_auth_find_user_by_password')) {
    function sc_auth_find_user_by_password($password, $cfg) {
        $none = array('ok' => false, 'username' => '', 'password' => '');
        if (!is_string($password) || !is_array($cfg)) {
            return $none;
        }
        $users = sc_auth_config_users($cfg);
        $match = null;
        for ($i = 0; $i < count($users); $i++) {
            $u = $users[$i];
            $ok = !empty($u['active'])
                  && sc_auth_safe_eq($password, (string)$u['password']);
            if ($ok && $match === null) {
                $match = $u;
            }
        }
        if ($match !== null) {
            $match['ok'] = true;
            return $match;
        }
        return $none;
    }
}

if (!function_exists('sc_auth_find_user_by_name')) {
    function sc_auth_find_user_by_name($username, $cfg) {
        $users = sc_auth_config_users($cfg);
        for ($i = 0; $i < count($users); $i++) {
            if ((string)$users[$i]['username'] === (string)$username) {
                return $users[$i];
            }
        }
        return null;
    }
}

if (!function_exists('sc_auth_can_edit_config')) {
    function sc_auth_can_edit_config($cfg, $username) {
        $user = sc_auth_find_user_by_name($username, $cfg);
        return is_array($user) && !empty($user['active'])
            && !empty($user['can_edit_config']);
    }
}

if (!function_exists('sc_auth_user_send_shortcut')) {
    function sc_auth_user_send_shortcut($cfg, $username) {
        $user = sc_auth_find_user_by_name($username, $cfg);
        if (!is_array($user) || empty($user['active'])) {
            return 'enter';
        }
        if (isset($user['send_shortcut'])
            && (string)$user['send_shortcut'] === 'shift_enter') {
            return 'shift_enter';
        }
        return 'enter';
    }
}

if (!function_exists('sc_auth_user_default_lang')) {
    function sc_auth_user_default_lang($cfg, $username, $fallback) {
        $user = sc_auth_find_user_by_name($username, $cfg);
        if (!is_array($user) || empty($user['active'])
            || !isset($user['default_lang'])
            || (string)$user['default_lang'] === '') {
            return (string)$fallback;
        }
        return (string)$user['default_lang'];
    }
}

if (!function_exists('sc_auth_provider_allowed')) {
    function sc_auth_provider_allowed($cfg, $provider_id, $username) {
        $model_id = (string)$provider_id;
        if ($model_id === '') {
            return false;
        }
        $user = sc_auth_find_user_by_name($username, $cfg);
        if (!is_array($user) || empty($user['active'])) {
            return false;
        }
        $text = isset($user['excluded_models'])
                ? trim((string)$user['excluded_models']) : '';
        if ($text === '') {
            return true;
        }
        if ($text === '*') {
            return false;
        }
        $list = explode(',', $text);
        for ($i = 0; $i < count($list); $i++) {
            if (trim((string)$list[$i]) === $model_id) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('sc_auth_provider_model_id')) {
    function sc_auth_provider_model_id($provider) {
        if (!is_array($provider)) {
            return '';
        }
        if (isset($provider['id']) && trim((string)$provider['id']) !== '') {
            return trim((string)$provider['id']);
        }
        return '';
    }
}

if (!function_exists('sc_auth_filter_providers')) {
    function sc_auth_filter_providers($providers, $cfg, $username) {
        $out = array();
        if (!is_array($providers)) {
            return $out;
        }
        for ($i = 0; $i < count($providers); $i++) {
            $p = $providers[$i];
            if (!is_array($p)) {
                continue;
            }
            $model_id = sc_auth_provider_model_id($p);
            if (sc_auth_provider_allowed($cfg, $model_id, $username)) {
                $out[] = $p;
            }
        }
        return $out;
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
        $user = sc_auth_find_user_by_password($password, $cfg);
        return !empty($user['ok']);
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
        sc_auth_apply_timezone($cfg);
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
        $user = sc_auth_find_user_by_password($password, $cfg);
        if (!empty($user['ok'])) {
            sc_auth_record_success($ip);
            sc_auth_log_attempt($ip, true, $cfg);
            return array('ok' => true, 'error' => '',
                         'username' => $user['username']);
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

/* sc_auth_token_max_age($cfg)
 *   Server-side session-token lifetime in seconds. Prefer the configured
 *   cookie lifetime; fall back to one day so stolen session-cookie values
 *   cannot remain valid indefinitely. */
if (!function_exists('sc_auth_token_max_age')) {
    function sc_auth_token_max_age($cfg) {
        $max = 86400;
        if (is_array($cfg) && isset($cfg['auth'])
            && is_array($cfg['auth'])) {
            if (isset($cfg['auth']['token_max_age'])
                && (int)$cfg['auth']['token_max_age'] > 0) {
                $max = (int)$cfg['auth']['token_max_age'];
            } elseif (isset($cfg['auth']['cookie_expires'])
                && (int)$cfg['auth']['cookie_expires'] > 0) {
                $max = (int)$cfg['auth']['cookie_expires'];
            }
        }
        return $max;
    }
}

if (!function_exists('sc_auth_install_secret_path')) {
    function sc_auth_install_secret_path() {
        $root = sc_auth_project_root();
        $dir  = $root . DIRECTORY_SEPARATOR . 'HISTORY';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . '.auth_secret';
    }
}

if (!function_exists('sc_auth_fallback_secret')) {
    function sc_auth_fallback_secret($cfg) {
        $parts = array('stoneChat');
        if (is_array($cfg)) {
            if (isset($cfg['auth']['cookie_name'])) {
                $parts[] = (string)$cfg['auth']['cookie_name'];
            }
            if (isset($cfg['server']['port'])) {
                $parts[] = (string)$cfg['server']['port'];
            }
        }
        $parts[] = sc_auth_project_root();
        return sha1(implode('|', $parts));
    }
}

if (!function_exists('sc_auth_install_secret')) {
    function sc_auth_install_secret($cfg) {
        if (is_array($cfg) && isset($cfg['auth'])
            && is_array($cfg['auth'])
            && isset($cfg['auth']['server_secret'])
            && trim((string)$cfg['auth']['server_secret']) !== '') {
            return trim((string)$cfg['auth']['server_secret']);
        }
        $path = sc_auth_install_secret_path();
        if (is_file($path) && is_readable($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && trim($raw) !== '') {
                return trim($raw);
            }
        }
        $seed = microtime(true) . '|' . mt_rand() . '|' . uniqid('', true)
              . '|' . sc_auth_project_root();
        if (function_exists('php_uname')) {
            $seed .= '|' . @php_uname();
        }
        $secret = sha1($seed) . sha1(strrev($seed));
        if ($path !== '') {
            @file_put_contents($path, $secret, LOCK_EX);
        }
        if (is_file($path) && is_readable($path)) {
            $raw2 = @file_get_contents($path);
            if (is_string($raw2) && trim($raw2) !== '') {
                return trim($raw2);
            }
        }
        return sc_auth_fallback_secret($cfg);
    }
}

if (!function_exists('sc_auth_sign')) {
    function sc_auth_sign($cfg, $purpose, $data) {
        $key = sc_auth_install_secret($cfg);
        $msg = (string)$purpose . '|' . (string)$data;
        if (function_exists('hash_hmac')) {
            return hash_hmac('sha256', $msg, $key);
        }
        return sha1($key . '|' . $msg . '|' . $key)
             . sha1(strrev($key) . '|' . $msg);
    }
}

/* sc_auth_csrf_token($session_token, $action)
 *   Stateless CSRF token tied to the current session token and action.
 *   One-hour ticks keep old form pages usable without making tokens
 *   long-lived. */
if (!function_exists('sc_auth_csrf_token')) {
    function sc_auth_csrf_token($session_token, $action) {
        $session = (string)$session_token;
        $act = (string)$action;
        if ($session === '' || $act === '') {
            return '';
        }
        $tick = (int)floor(time() / 3600);
        $sig = sc_auth_sign(array(), 'csrf', $tick . '|' . $act
                            . '|' . $session);
        return 'csrf1:' . $tick . ':' . $sig;
    }
}

if (!function_exists('sc_auth_csrf_verify')) {
    function sc_auth_csrf_verify($session_token, $action, $token) {
        $session = (string)$session_token;
        $act = (string)$action;
        $tok = (string)$token;
        if ($session === '' || $act === '' || $tok === '') {
            return false;
        }
        if (strpos($tok, 'csrf1:') !== 0) {
            return false;
        }
        $parts = explode(':', $tok);
        if (count($parts) !== 3) {
            return false;
        }
        $tick = (int)$parts[1];
        if ($tick <= 0) {
            return false;
        }
        $now_tick = (int)floor(time() / 3600);
        if ($tick < ($now_tick - 1) || $tick > ($now_tick + 1)) {
            return false;
        }
        $expected = sc_auth_sign(array(), 'csrf', $tick . '|' . $act
                                 . '|' . $session);
        return sc_auth_safe_eq($parts[2], $expected);
    }
}

/* sc_auth_csrf_tokens($session_token, $actions)
 *   Build a map of action name => CSRF token for classic XHR clients. */
if (!function_exists('sc_auth_csrf_tokens')) {
    function sc_auth_csrf_tokens($session_token, $actions) {
        $out = array();
        if (!is_array($actions)) {
            return $out;
        }
        for ($i = 0; $i < count($actions); $i++) {
            $action = (string)$actions[$i];
            if ($action === '') {
                continue;
            }
            $token = sc_auth_csrf_token($session_token, $action);
            if ($token !== '') {
                $out[$action] = $token;
            }
        }
        return $out;
    }
}

/* sc_auth_cookie_name($cfg)
 *   Shared session-cookie name resolver for API and editor code. */
if (!function_exists('sc_auth_cookie_name')) {
    function sc_auth_cookie_name($cfg) {
        if (is_array($cfg) && isset($cfg['auth'])
            && is_array($cfg['auth'])
            && isset($cfg['auth']['cookie_name'])
            && (string)$cfg['auth']['cookie_name'] !== '') {
            return (string)$cfg['auth']['cookie_name'];
        }
        return 'sc_auth';
    }
}

/* sc_auth_session_token_from_cookie($cfg)
 *   Read the current session token from the configured cookie. The legacy
 *   sc_session fallback is kept for old installs. */
if (!function_exists('sc_auth_session_token_from_cookie')) {
    function sc_auth_session_token_from_cookie($cfg) {
        $name = sc_auth_cookie_name($cfg);
        $token = '';
        if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
            $token = $_COOKIE[$name];
        }
        if ($token === '' && isset($_COOKIE['sc_session'])
            && is_string($_COOKIE['sc_session'])) {
            $token = $_COOKIE['sc_session'];
        }
        return $token;
    }
}

/* sc_auth_generate_token($cfg, $user)
 *   Build a signed session token. The token carries only username;
 *   rights are re-read from CONF.ini on each request. */
if (!function_exists('sc_auth_generate_token')) {
    function sc_auth_generate_token($cfg, $user = null) {
        if (!is_array($user)) {
            $user = array();
        }
        $username = isset($user['username']) ? (string)$user['username'] : 'User';
        $ts = time();
        $sig = sc_auth_sign($cfg, 'session', $ts . '|' . $username);
        return 'scv3:' . $ts . ':' . rawurlencode($username) . ':' . $sig;
    }
}

/* sc_auth_token_context($token, $cfg)
 *   Verify a stateless signed token and return current user metadata. */
if (!function_exists('sc_auth_token_context')) {
    function sc_auth_token_context($token, $cfg) {
        $bad = array('ok' => false, 'username' => '');
        if (!is_string($token) || strlen($token) < 6) {
            return $bad;
        }
        if (strpos($token, 'scv3:') === 0) {
            $parts = explode(':', $token);
            if (count($parts) !== 4) {
                return $bad;
            }
            $ts = $parts[1];
            $username = rawurldecode($parts[2]);
            $sig = $parts[3];
            $u = sc_auth_find_user_by_name($username, $cfg);
            if (!is_array($u)) {
                return $bad;
            }
            if (empty($u['active'])) {
                return $bad;
            }
            if (!preg_match('/^[0-9]+$/', (string)$ts)) {
                return $bad;
            }
            $ts_i = (int)$ts;
            $now = time();
            if ($ts_i <= 0 || $ts_i > ($now + 300)) {
                return $bad;
            }
            $max_age = sc_auth_token_max_age($cfg);
            if ($max_age > 0 && ($now - $ts_i) > $max_age) {
                return $bad;
            }
            $expected = sc_auth_sign($cfg, 'session',
                                     $ts . '|' . $username);
            if (!sc_auth_safe_eq($sig, $expected)) {
                return $bad;
            }
            return array('ok' => true, 'username' => $username);
        }
        return $bad;
    }
}

/* sc_auth_verify_token($token, $cfg)
 *   Verify a stateless signed token. */
if (!function_exists('sc_auth_verify_token')) {
    function sc_auth_verify_token($token, $cfg) {
        $ctx = sc_auth_token_context($token, $cfg);
        return !empty($ctx['ok']);
    }
}
