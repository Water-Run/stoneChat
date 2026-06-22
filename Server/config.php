<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/config.php
 *
 * Load and validate CONF.ini. Public helpers (sc_-prefixed, guarded
 * with function_exists for include-twice safety):
 *
 *   sc_load_config($path)              parse CONF.ini; empty on failure
 *   sc_load_providers($path)           [Provider N] -> normalized list
 *   sc_provider_section_name($name)    is the section a "Provider N"?
 *   sc_is_placeholder_password($pw)    is the password an unfilled stub?
 *   sc_validate_path_resolve($p, $b)   resolve a path under a base
 *   sc_validate_config($cfg)           check keys; error code list
 *   sc_config_fatal_errors($errors)    startup-blocking subset
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_load_config($ini_path)
 *   Parse CONF.ini into a nested array; empty array on any failure. */
if (!function_exists('sc_load_config')) {
    function sc_load_config($ini_path) {
        if (!is_string($ini_path) || !is_file($ini_path)
            || !is_readable($ini_path)) {
            return array();
        }
        $parsed = @parse_ini_file($ini_path, true);
        if (!is_array($parsed)) {
            return array();
        }
        if (isset($parsed['paths']) && is_array($parsed['paths']) && isset($parsed['paths']['stunnel'])) {
            $sp = $parsed['paths']['stunnel'];
            if (!is_file($sp)) {
                $sp_fallback = 'C:\\Program Files (x86)\\stunnel\\bin\\stunnel.exe';
                if (is_file($sp_fallback)) {
                    $parsed['paths']['stunnel'] = $sp_fallback;
                }
            }
        }
        return $parsed;
    }
}

/* sc_provider_section_name($name)
 *   Does the section name follow the "Provider N" convention? */
if (!function_exists('sc_provider_section_name')) {
    function sc_provider_section_name($name) {
        if (!is_string($name)) {
            return false;
        }
        return (bool)preg_match('/^Provider\s+\d+$/i', $name);
    }
}

/* sc_ini_raw_value($value)
 *   Minimal raw INI value cleanup for provider scalar overrides. */
if (!function_exists('sc_ini_raw_value')) {
    function sc_ini_raw_value($value) {
        $text = trim((string)$value);
        $len = strlen($text);
        if ($len >= 2) {
            $first = substr($text, 0, 1);
            $last  = substr($text, -1);
            if (($first === '"' && $last === '"')
                || ($first === "'" && $last === "'")) {
                return substr($text, 1, $len - 2);
            }
        }
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = substr($text, $i, 1);
            if ($ch === ';' || $ch === '#') {
                break;
            }
            $out .= $ch;
        }
        return trim($out);
    }
}

/* sc_load_provider_raw_scalars($ini_path)
 *   parse_ini_file() turns false/no/off into empty strings in PHP 5.x.
 *   Read only provider stream/max_tokens/timeout as raw text. */
if (!function_exists('sc_load_provider_raw_scalars')) {
    function sc_load_provider_raw_scalars($ini_path) {
        if (!is_string($ini_path) || !is_file($ini_path)
            || !is_readable($ini_path)) {
            return array();
        }
        $lines = @file($ini_path);
        if (!is_array($lines)) {
            return array();
        }
        $out = array();
        $current = 0;
        foreach ($lines as $line) {
            $t = trim((string)$line);
            if ($t === '' || substr($t, 0, 1) === ';'
                || substr($t, 0, 1) === '#') {
                continue;
            }
            if (preg_match('/^\[\s*Provider\s+(\d+)\s*\]$/i', $t, $m)) {
                $current = (int)$m[1];
                if (!isset($out[$current])) {
                    $out[$current] = array();
                }
                continue;
            }
            if (substr($t, 0, 1) === '[') {
                $current = 0;
                continue;
            }
            if ($current <= 0) {
                continue;
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/',
                            $t, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            if ($key !== 'stream' && $key !== 'max_tokens'
                && $key !== 'timeout') {
                continue;
            }
            $out[$current][$key] = sc_ini_raw_value($m[2]);
        }
        return $out;
    }
}

/* sc_load_providers($ini_path)
 *   Read [Provider N] sections from CONF.ini as a normalized list.
 *
 *   Each provider is array(
 *     'id'       => string, // display id
 *     'label'    => string, // display name
 *     'type'     => string, // 'openai' or 'anthropic'
 *     'api_base' => string,
 *     'api_key'  => string,
 *     'model'    => string,
 *     'stream'   => string, // optional raw scalar
 *     'max_tokens' => string, // optional raw scalar
 *     'timeout'  => string, // optional raw scalar
 *   )
 *
 *   Returned in numeric order (Provider 1, 2, ...). Sections with
 *   non-array bodies are skipped. */
if (!function_exists('sc_load_providers')) {
    function sc_load_providers($ini_path) {
        $cfg = sc_load_config($ini_path);
        if (!is_array($cfg) || empty($cfg)) {
            return array();
        }
        $buckets = array();
        foreach ($cfg as $section_name => $section_data) {
            if (!sc_provider_section_name($section_name)) {
                continue;
            }
            if (!is_array($section_data)) {
                continue;
            }
            if (!preg_match('/^Provider\s+(\d+)$/i', $section_name, $m)) {
                continue;
            }
            $buckets[(int)$m[1]] = $section_data;
        }
        if (empty($buckets)) {
            return array();
        }
        ksort($buckets, SORT_NUMERIC);
        $raw_scalars = sc_load_provider_raw_scalars($ini_path);
        $providers = array();
        foreach ($buckets as $provider_no => $section_data) {
            $raw = isset($raw_scalars[$provider_no])
                   && is_array($raw_scalars[$provider_no])
                   ? $raw_scalars[$provider_no] : array();
            $stream = isset($raw['stream'])
                      ? (string)$raw['stream']
                      : (isset($section_data['stream'])
                         ? (string)$section_data['stream'] : '');
            $max_tokens = isset($raw['max_tokens'])
                          ? (string)$raw['max_tokens']
                          : (isset($section_data['max_tokens'])
                             ? (string)$section_data['max_tokens'] : '');
            $timeout = isset($raw['timeout'])
                       ? (string)$raw['timeout']
                       : (isset($section_data['timeout'])
                          ? (string)$section_data['timeout'] : '');
            $providers[] = array(
                'id'       => isset($section_data['id'])
                              ? (string)$section_data['id'] : '',
                'label'    => isset($section_data['label'])
                              ? (string)$section_data['label'] : '',
                'type'     => isset($section_data['type'])
                              ? (string)$section_data['type'] : '',
                'api_base' => isset($section_data['api_base'])
                              ? (string)$section_data['api_base'] : '',
                'api_key'  => isset($section_data['api_key'])
                              ? (string)$section_data['api_key'] : '',
                'model'    => isset($section_data['model'])
                              ? (string)$section_data['model'] : '',
                'stream'   => $stream,
                'max_tokens' => $max_tokens,
                'timeout'  => $timeout,
            );
        }
        return $providers;
    }
}

/* sc_config_fatal_errors($errors)
 *   Filter sc_validate_config() output to errors that must block
 *   startup. Provider-specific problems are shown in the provider UI;
 *   stunnel/cacert paths are checked by RUN.bat with better hints. */
if (!function_exists('sc_config_fatal_errors')) {
    function sc_config_fatal_errors($errors) {
        if (!is_array($errors)) {
            return array('config_errors_not_array');
        }
        $fatal = array();
        $fatal_map = array(
            'config_not_array' => true,
            'missing_server_port' => true,
            'missing_auth_password' => true,
            'auth_password_is_placeholder' => true,
        );
        foreach ($errors as $err) {
            $code = (string)$err;
            if (isset($fatal_map[$code])) {
                $fatal[] = $code;
            }
        }
        return $fatal;
    }
}

/* sc_is_placeholder_password($password)
 *   Heuristic: is the password value an unfilled CONF.ini stub?
 *
 *   Empty strings, "****", and the "YOUR_*_HERE" stub pattern are
 *   treated as placeholders. Mirrors the api/providers.php check
 *   so the same strings are flagged the same way in both
 *   validators and the public /api/config endpoint. */
if (!function_exists('sc_is_placeholder_password')) {
    function sc_is_placeholder_password($password) {
        if (!is_string($password)) {
            return true;
        }
        $t = trim($password);
        if ($t === '' || $t === '****') {
            return true;
        }
        if (preg_match('/^YOUR_[A-Z0-9_]*_HERE$/', $t)) {
            return true;
        }
        return false;
    }
}

/* sc_validate_path_resolve($path, $base_dir)
 *   Resolve a CONF.ini-relative path against the project root,
 *   normalising any ".." segments so is_file() sees a real path.
 *
 *   Handles:
 *     - Windows drive-letter absolute paths ("C:\..." or "C:/...")
 *     - POSIX absolute paths ("/...")
 *     - Any path starting with "\" (Windows root-relative)
 *     - Relative paths: joined onto $base_dir, walked to fold
 *       away "." and ".." segments.
 *
 *   Returns the absolute path; the original $path on failure. */
if (!function_exists('sc_validate_path_resolve')) {
    function sc_validate_path_resolve($path, $base_dir) {
        if (!is_string($path) || $path === '') {
            return '';
        }
        if (strlen($path) >= 2 && $path[1] === ':') {
            return str_replace('/', DIRECTORY_SEPARATOR, $path);
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return str_replace('/', DIRECTORY_SEPARATOR, $path);
        }
        if (!is_string($base_dir) || $base_dir === '') {
            return $path;
        }
        $base = rtrim($base_dir, '/\\');
        $sep  = (strpos($base, '\\') !== false)
                ? '\\' : DIRECTORY_SEPARATOR;
        $joined = $base . $sep . str_replace('/', $sep, $path);
        /* walk segments to fold away "." and ".." */
        $is_unc = (strlen($base) >= 2 && $base[1] === ':');
        $prefix = '';
        if ($is_unc) {
            $prefix = substr($joined, 0, 2) . $sep;
        } elseif (strlen($joined) > 0
                  && ($joined[0] === '/' || $joined[0] === '\\')) {
            $prefix = $joined[0];
        }
        $body   = $is_unc ? substr($joined, 2) : $joined;
        $body   = ltrim($body, '/\\');
        $parts  = explode($sep, $body);
        $stack  = array();
        foreach ($parts as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (!empty($stack)) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $seg;
        }
        if (empty($stack)) {
            return $prefix !== '' ? rtrim($prefix, $sep) : $sep;
        }
        return $prefix . implode($sep, $stack);
    }
}

/* sc_validate_config($cfg)
 *   Check a parsed stoneChat config for required keys and provider
 *   integrity. Returns an array of short error codes (empty on
 *   success); secret values are never echoed.
 *
 *   Required:
 *     - [server].port       (non-empty)
 *     - [auth].password     (non-empty AND not a placeholder)
 *
 *   Soft checks (reported but not fatal here; the installer/UI may
 *   downgrade them to warnings):
 *     - [paths].stunnel / [paths].ca_cert: file exists when set
 *     - each [Provider N].api_key is non-placeholder
 *     - each [Provider N].type is one of: "openai", "anthropic" */
if (!function_exists('sc_validate_config')) {
    function sc_validate_config($cfg) {
        $errors = array();
        if (!is_array($cfg)) {
            return array('config_not_array');
        }
        /* [server].port must be present and non-empty. */
        $has_port = isset($cfg['server']) && is_array($cfg['server'])
                    && isset($cfg['server']['port'])
                    && (string)$cfg['server']['port'] !== '';
        if (!$has_port) {
            $errors[] = 'missing_server_port';
        }
        /* [auth].password must be present, non-empty, not a placeholder. */
        $has_pass = false;
        $pass_placeholder = true;
        if (isset($cfg['auth']) && is_array($cfg['auth'])
            && isset($cfg['auth']['password'])) {
            $raw_pass = (string)$cfg['auth']['password'];
            if ($raw_pass !== '') {
                $has_pass = true;
                $pass_placeholder = sc_is_placeholder_password($raw_pass);
            }
        }
        if (!$has_pass) {
            $errors[] = 'missing_auth_password';
        } elseif ($pass_placeholder) {
            $errors[] = 'auth_password_is_placeholder';
        }
        /* [paths].stunnel / [paths].ca_cert: soft-check existence.
         * CONF.ini stores these as either absolute or relative
         * paths. The historical convention is that they are
         * relative to the Server/ subdirectory (one level deeper
         * than the project root), because that is where the
         * ModernNetwork tunnel code that consumes them actually
         * runs. We therefore resolve against dirname(__FILE__)
         * (== Server/) and then call realpath() so any literal
         * ".." segments collapse to a real OS path that is_file()
         * can verify. */
        if (isset($cfg['paths']) && is_array($cfg['paths'])) {
            $resolve_base = dirname(__FILE__);
            if (isset($cfg['paths']['stunnel'])
                && (string)$cfg['paths']['stunnel'] !== '') {
                $sp = (string)$cfg['paths']['stunnel'];
                $sp_abs = sc_validate_path_resolve($sp, $resolve_base);
                if (is_string($sp_abs) && $sp_abs !== '') {
                    $sp_real = @realpath($sp_abs);
                    if (is_string($sp_real) && $sp_real !== '') {
                        $sp_abs = $sp_real;
                    }
                }
                if (!is_file($sp_abs)) {
                    $errors[] = 'paths_stunnel_missing';
                }
            }
            if (isset($cfg['paths']['ca_cert'])
                && (string)$cfg['paths']['ca_cert'] !== '') {
                $cp = (string)$cfg['paths']['ca_cert'];
                $cp_abs = sc_validate_path_resolve($cp, $resolve_base);
                if (is_string($cp_abs) && $cp_abs !== '') {
                    $cp_real = @realpath($cp_abs);
                    if (is_string($cp_real) && $cp_real !== '') {
                        $cp_abs = $cp_real;
                    }
                }
                if (!is_file($cp_abs)) {
                    $errors[] = 'paths_ca_cert_missing';
                }
            }
        }
        /* Provider sections: each must declare an api_key and a
         * supported type. */
        $supported_types = array('openai' => true, 'anthropic' => true);
        foreach ($cfg as $section_name => $section_data) {
            if (!sc_provider_section_name($section_name)) {
                continue;
            }
            if (!is_array($section_data)) {
                $errors[] = $section_name . '_not_section';
                continue;
            }
            $key = isset($section_data['api_key'])
                   ? (string)$section_data['api_key'] : '';
            if ($key === '') {
                $errors[] = $section_name . '_missing_api_key';
            } elseif (sc_is_placeholder_password($key)) {
                $errors[] = $section_name . '_api_key_is_placeholder';
            }
            $type = isset($section_data['type'])
                    ? strtolower(trim((string)$section_data['type'])) : '';
            if ($type === '' || !isset($supported_types[$type])) {
                $errors[] = $section_name . '_invalid_type';
            }
        }
        return $errors;
    }
}
