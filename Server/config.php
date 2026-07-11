<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/config.php
 *
 * Load and validate CONF.ini. Public helpers (sc_-prefixed, guarded
 * with function_exists for include-twice safety):
 *
 *   sc_load_config($path)              parse CONF.ini; empty on failure
 *   sc_load_providers($path)           [Model NAME] -> normalized list
 *   sc_model_section_name($name)       extract NAME from [Model NAME]
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

/* sc_model_section_name($name)
 *   Return the NAME part from a [Model NAME] section, or ''. */
if (!function_exists('sc_model_section_name')) {
    function sc_model_section_name($name) {
        if (!is_string($name)) {
            return '';
        }
        if (preg_match('/^Model[ \t]+(.+)$/i', $name, $m)) {
            return trim((string)$m[1]);
        }
        return '';
    }
}

/* sc_provider_section_name($name)
 *   Old configs used [Provider N]. New configs must not. */
if (!function_exists('sc_provider_section_name')) {
    function sc_provider_section_name($name) {
        if (!is_string($name)) {
            return false;
        }
        return (bool)preg_match('/^Provider\s+\d+$/i', $name);
    }
}

/* sc_ini_raw_value($value)
 *   Minimal raw INI value cleanup for model scalar overrides. */
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

/* sc_load_model_raw_scalars($ini_path)
 *   parse_ini_file() turns false/no/off into empty strings in PHP 5.x.
 *   Read only model stream/max_tokens/timeout as raw text. */
if (!function_exists('sc_load_model_raw_scalars')) {
    function sc_load_model_raw_scalars($ini_path) {
        if (!is_string($ini_path) || !is_file($ini_path)
            || !is_readable($ini_path)) {
            return array();
        }
        $lines = @file($ini_path);
        if (!is_array($lines)) {
            return array();
        }
        $out = array();
        $current = '';
        foreach ($lines as $line) {
            $t = trim((string)$line);
            if ($t === '' || substr($t, 0, 1) === ';'
                || substr($t, 0, 1) === '#') {
                continue;
            }
            if (preg_match('/^\[\s*Model[ \t]+(.+?)\s*\]$/i', $t, $m)) {
                $current = trim((string)$m[1]);
                if (!isset($out[$current])) {
                    $out[$current] = array();
                }
                continue;
            }
            if (substr($t, 0, 1) === '[') {
                $current = '';
                continue;
            }
            if ($current === '') {
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

/* sc_load_provider_raw_scalars($ini_path)
 *   Compatibility wrapper for older includes. */
if (!function_exists('sc_load_provider_raw_scalars')) {
    function sc_load_provider_raw_scalars($ini_path) {
        return sc_load_model_raw_scalars($ini_path);
    }
}

/* sc_load_providers($ini_path)
 *   Read [Model NAME] sections from CONF.ini as a normalized list.
 *
 *   Each model is array(
 *     'id'       => string, // permission/config name
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
 *   Returned in file order. Inactive sections are skipped. */
if (!function_exists('sc_load_providers')) {
    function sc_load_providers($ini_path) {
        $cfg = sc_load_config($ini_path);
        if (!is_array($cfg) || empty($cfg)) {
            return array();
        }
        $raw_scalars = sc_load_model_raw_scalars($ini_path);
        $providers = array();
        foreach ($cfg as $section_name => $section_data) {
            $model_name = sc_model_section_name($section_name);
            if ($model_name === '' || !is_array($section_data)) {
                continue;
            }
            $active_text = isset($section_data['active'])
                           ? strtolower(trim((string)$section_data['active']))
                           : '1';
            if ($active_text === '0' || $active_text === ''
                || $active_text === 'false' || $active_text === 'no'
                || $active_text === 'off') {
                continue;
            }
            $raw = isset($raw_scalars[$model_name])
                   && is_array($raw_scalars[$model_name])
                   ? $raw_scalars[$model_name] : array();
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
                'id'       => $model_name,
                'active'   => isset($section_data['active'])
                              ? (string)$section_data['active'] : '1',
                'label'    => isset($section_data['label'])
                              ? (string)$section_data['label'] : $model_name,
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
 *   startup. Model-specific problems must be fixed before boot.
 *   stunnel/cacert paths are checked by RUN.cmd with better hints. */
if (!function_exists('sc_config_fatal_errors')) {
    function sc_config_fatal_errors($errors) {
        if (!is_array($errors)) {
            return array('config_errors_not_array');
        }
        $fatal = array();
        $fatal_map = array(
            'config_not_array' => true,
            'missing_server_port' => true,
            'missing_auth_user' => true,
            'auth_user_password_is_placeholder' => true,
            'auth_user_password_duplicate' => true,
        );
        foreach ($errors as $err) {
            $code = (string)$err;
            if (isset($fatal_map[$code])) {
                $fatal[] = $code;
                continue;
            }
            if (preg_match('/^User .+_(active|can_edit_config)_invalid$/', $code)
                || preg_match('/^User .+_excluded_model_missing:/', $code)
                || preg_match('/^User .+_excluded_model_inactive:/', $code)
                || preg_match('/^User .+_password_duplicate$/', $code)
                || preg_match('/^Model .+_(active_invalid|missing_api_base|missing_api_key|api_key_is_placeholder|invalid_type|missing_model)$/', $code)
                || preg_match('/^Provider [0-9]+_section_not_supported$/', $code)) {
                $fatal[] = $code;
            }
        }
        return $fatal;
    }
}

if (!function_exists('sc_config_bool_value_valid')) {
    function sc_config_bool_value_valid($value) {
        $v = strtolower(trim((string)$value));
        return ($v === '' || $v === '1' || $v === '0' || $v === 'true'
            || $v === 'false' || $v === 'yes' || $v === 'no'
            || $v === 'on' || $v === 'off');
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
        if (preg_match('/^REPLACE_WITH_[A-Z0-9_]*PASSWORD$/', $t)) {
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
 *   Check a parsed stoneChat config for required keys and model
 *   integrity. Returns an array of short error codes (empty on
 *   success); secret values are never echoed.
 *
 *   Required:
 *     - [server].port       (non-empty)
 *     - at least one [User NAME].password (non-empty, not a placeholder)
 *
 *   Soft checks (reported but not fatal here; RUN.cmd gives some of
 *   them clearer operator hints):
 *     - [paths].stunnel / [paths].ca_cert: file exists when set
 *     - each active [Model NAME].api_key is non-placeholder
 *     - each active [Model NAME].type is "openai" or "anthropic" */
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
        /* Model sections are complete usable units. */
        $known_models = array();
        $active_models = array();
        foreach ($cfg as $section => $section_data) {
            if (!is_string($section) || !is_array($section_data)) {
                continue;
            }
            $name = sc_model_section_name($section);
            if ($name === '') {
                continue;
            }
            $known_models[$name] = true;
            $active_text = isset($section_data['active'])
                           ? strtolower(trim((string)$section_data['active']))
                           : '1';
            if (!($active_text === '0' || $active_text === ''
                || $active_text === 'false' || $active_text === 'no'
                || $active_text === 'off')) {
                $active_models[$name] = true;
            }
        }

        /* One [User NAME].password must be present and not a placeholder.
         * Passwords must be unique across users: login matches by password
         * only, so duplicates make accounts indistinguishable. */
        $has_user = false;
        $user_placeholder = false;
        $seen_passwords = array();
        $password_duplicate = false;
        foreach ($cfg as $section => $section_data) {
            if (!is_string($section) || !is_array($section_data)) {
                continue;
            }
            if (!preg_match('/^User[ \t]+.+$/i', $section)) {
                continue;
            }
            $has_user = true;
            $raw_pass = isset($section_data['password'])
                        ? (string)$section_data['password'] : '';
            if (sc_is_placeholder_password($raw_pass)) {
                $user_placeholder = true;
            }
            $pass_key = $raw_pass;
            if ($pass_key !== '' && !sc_is_placeholder_password($raw_pass)) {
                if (isset($seen_passwords[$pass_key])) {
                    $password_duplicate = true;
                    $errors[] = $section . '_password_duplicate';
                    $errors[] = $seen_passwords[$pass_key]
                              . '_password_duplicate';
                } else {
                    $seen_passwords[$pass_key] = $section;
                }
            }
            if (isset($section_data['active'])
                && !sc_config_bool_value_valid($section_data['active'])) {
                $errors[] = $section . '_active_invalid';
            }
            if (isset($section_data['can_edit_config'])
                && !sc_config_bool_value_valid($section_data['can_edit_config'])) {
                $errors[] = $section . '_can_edit_config_invalid';
            }
            $excluded_models = isset($section_data['excluded_models'])
                               ? trim((string)$section_data['excluded_models']) : '';
            if ($excluded_models !== '' && $excluded_models !== '*') {
                $parts = explode(',', $excluded_models);
                for ($i = 0; $i < count($parts); $i++) {
                    $model_name = trim((string)$parts[$i]);
                    if ($model_name === '') {
                        continue;
                    }
                    if (!isset($known_models[$model_name])) {
                        $errors[] = $section . '_excluded_model_missing:'
                                  . $model_name;
                    } elseif (!isset($active_models[$model_name])) {
                        $errors[] = $section . '_excluded_model_inactive:'
                                  . $model_name;
                    }
                }
            }
        }
        if (!$has_user) {
            $errors[] = 'missing_auth_user';
        } elseif ($user_placeholder) {
            $errors[] = 'auth_user_password_is_placeholder';
        }
        if ($password_duplicate) {
            $errors[] = 'auth_user_password_duplicate';
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
        /* Old provider sections are no longer accepted. */
        foreach ($cfg as $section_name => $section_data) {
            if (sc_provider_section_name($section_name)) {
                $errors[] = $section_name . '_section_not_supported';
            }
        }

        /* Model sections: each active one must declare a complete
         * API unit. */
        $supported_types = array('openai' => true, 'anthropic' => true);
        foreach ($cfg as $section_name => $section_data) {
            if (sc_model_section_name($section_name) === '') {
                continue;
            }
            if (!is_array($section_data)) {
                $errors[] = $section_name . '_not_section';
                continue;
            }
            $active_text = isset($section_data['active'])
                           ? (string)$section_data['active'] : '1';
            if (!sc_config_bool_value_valid($active_text)) {
                $errors[] = $section_name . '_active_invalid';
            }
            $active = !(strtolower(trim($active_text)) === '0'
                        || strtolower(trim($active_text)) === ''
                        || strtolower(trim($active_text)) === 'false'
                        || strtolower(trim($active_text)) === 'no'
                        || strtolower(trim($active_text)) === 'off');
            if (!$active) {
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
            $api_base = isset($section_data['api_base'])
                        ? trim((string)$section_data['api_base']) : '';
            if ($api_base === '') {
                $errors[] = $section_name . '_missing_api_base';
            }
            $api_model = isset($section_data['model'])
                         ? trim((string)$section_data['model']) : '';
            if ($api_model === '') {
                $errors[] = $section_name . '_missing_model';
            }
        }
        return $errors;
    }
}
