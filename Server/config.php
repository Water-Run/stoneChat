<?php
/**
 * stoneChat Server config loader and validator.
 *
 * Public functions (all sc_-prefixed, each wrapped in a function_exists guard):
 *   sc_load_config($ini_path)
 *       Parse CONF.ini into a nested array; empty array on any failure.
 *   sc_load_providers($ini_path)
 *       Extract all [Provider N] sections as a normalized list
 *       (id/label/type/api_base/api_key/model), ordered by N.
 *   sc_validate_config($cfg)
 *       Check a parsed config for required keys and provider integrity.
 *       Returns an array of short error codes (empty array on success).
 *
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no namespaces).
 */

if (!function_exists('sc_load_config')) {
    /**
     * Load the stoneChat INI config file as a nested array.
     *
     * @param string $ini_path Absolute or relative path to CONF.ini.
     * @return array Parsed config, or empty array on any failure.
     */
    function sc_load_config($ini_path) {
        if (!is_string($ini_path) || !is_file($ini_path) || !is_readable($ini_path)) {
            return array();
        }
        $parsed = @parse_ini_file($ini_path, true);
        if (!is_array($parsed)) {
            return array();
        }
        return $parsed;
    }
}

if (!function_exists('sc_provider_section_name')) {
    /**
     * Test whether a section name follows the "Provider N" convention.
     *
     * @param string $name Section name from parse_ini_file().
     * @return bool true if the name is "Provider" followed by one or more digits.
     */
    function sc_provider_section_name($name) {
        if (!is_string($name)) {
            return false;
        }
        return (bool)preg_match('/^Provider\s+\d+$/i', $name);
    }
}

if (!function_exists('sc_load_providers')) {
    /**
     * Load [Provider N] sections from CONF.ini as a normalized list.
     *
     * Each provider is array(
     *   'id'       => string,  // from "id"       (display id)
     *   'label'    => string,  // from "label"    (display name)
     *   'type'     => string,  // from "type"     ("openai" or "anthropic")
     *   'api_base' => string,  // from "api_base"
     *   'api_key'  => string,  // from "api_key"
     *   'model'    => string,  // from "model"
     * )
     *
     * Sections are returned in numeric order (Provider 1 first, then 2, ...).
     * Sections with non-array bodies are skipped.
     *
     * @param string $ini_path Absolute or relative path to CONF.ini.
     * @return array List of provider arrays; empty array on failure or no providers.
     */
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
        $providers = array();
        foreach ($buckets as $section_data) {
            $providers[] = array(
                'id'       => isset($section_data['id'])       ? (string)$section_data['id']       : '',
                'label'    => isset($section_data['label'])    ? (string)$section_data['label']    : '',
                'type'     => isset($section_data['type'])     ? (string)$section_data['type']     : '',
                'api_base' => isset($section_data['api_base']) ? (string)$section_data['api_base'] : '',
                'api_key'  => isset($section_data['api_key'])  ? (string)$section_data['api_key']  : '',
                'model'    => isset($section_data['model'])    ? (string)$section_data['model']    : '',
            );
        }
        return $providers;
    }
}

if (!function_exists('sc_is_placeholder_password')) {
    /**
     * Heuristic: is this password value an unfilled CONF.ini placeholder?
     *
     * Treats empty strings, "****", and the common "YOUR_*_HERE" stub
     * pattern as placeholders. Anything else is considered "set".
     *
     * Mirrors sc_api_providers_is_placeholder_key() (in Server/api/
     * providers.php) so the same strings are flagged the same way in
     * both validators and the public /api/config endpoint.
     *
     * @param mixed $password Raw password value.
     * @return bool true if the value should be treated as unset.
     */
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

if (!function_exists('sc_validate_path_resolve')) {
    /**
     * Resolve a CONF.ini-relative path against the project root,
     * normalizing any ".." segments so is_file() sees a real path.
     *
     * Independent of ModernNetwork/proxy.php::sc_resolve_path() so
     * the config validator does not have to depend on a transport
     * module. Handles:
     *   - Windows drive-letter absolute paths ("C:\..." or "C:/...")
     *   - POSIX absolute paths ("/...")
     *   - Any path starting with "\" (Windows root-relative)
     *   - Relative paths: joined onto $base_dir, then walked to
     *     fold away "." and ".." segments.
     *
     * @param string $path     Raw path from CONF.ini.
     * @param string $base_dir Project root (parent of Server/).
     * @return string          Absolute path; original $path on failure.
     */
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
        $joined = $base . $sep
                . str_replace('/', $sep, $path);
        // Walk segments to fold away "." and "..".
        $is_unc = (strlen($base) >= 2 && $base[1] === ':');
        $prefix = $is_unc
                ? substr($joined, 0, 2) . $sep // "C:\\"
                : '';
        $body   = $is_unc
                ? substr($joined, 2) : $joined;
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

if (!function_exists('sc_validate_config')) {
    /**
     * Validate a parsed stoneChat config array.
     *
     * Required keys:
     *   - [server].port   (non-empty)
     *   - [auth].password (non-empty AND not a placeholder)
     *
     * Soft checks (reported but not fatal in this validator; the
     * installer/UI may downgrade them to warnings):
     *   - [paths].stunnel  file exists, when set
     *   - [paths].ca_cert  file exists, when set
     *   - each [Provider N].api_key is non-placeholder
     *   - each [Provider N].type is one of: "openai", "anthropic"
     *
     * Error messages are short codes; the secret values themselves are
     * never echoed.
     *
     * @param array $cfg Parsed config (output of sc_load_config()).
     * @return array List of error code strings; empty array on success.
     */
    function sc_validate_config($cfg) {
        $errors = array();
        if (!is_array($cfg)) {
            return array('config_not_array');
        }
        // [server].port must be present and non-empty.
        $has_port = isset($cfg['server']) && is_array($cfg['server'])
                    && isset($cfg['server']['port'])
                    && (string)$cfg['server']['port'] !== '';
        if (!$has_port) {
            $errors[] = 'missing_server_port';
        }
        // [auth].password must be present, non-empty, and not a placeholder.
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
        // [paths].stunnel, [paths].ca_cert: soft-check existence when set.
        // CONF.ini stores these as either absolute or relative paths.
        // The historical convention is that they are relative to the
        // Server/ subdirectory (i.e. one level deeper than the
        // project root), because that is where the ModernNetwork
        // tunnel code that consumes them actually runs. We therefore
        // resolve against dirname(__FILE__) (== Server/) and then
        // call realpath() so any literal ".." segments collapse to
        // a real OS path that is_file() can verify.
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
        // Provider sections: each must declare an api_key and a supported type.
        $supported_types = array('openai' => true, 'anthropic' => true);
        foreach ($cfg as $section_name => $section_data) {
            if (!sc_provider_section_name($section_name)) {
                continue;
            }
            if (!is_array($section_data)) {
                $errors[] = $section_name . '_not_section';
                continue;
            }
            $key = isset($section_data['api_key']) ? (string)$section_data['api_key'] : '';
            if ($key === '') {
                $errors[] = $section_name . '_missing_api_key';
            } elseif (sc_is_placeholder_password($key)) {
                $errors[] = $section_name . '_api_key_is_placeholder';
            }
            $type = isset($section_data['type']) ? strtolower(trim((string)$section_data['type'])) : '';
            if ($type === '' || !isset($supported_types[$type])) {
                $errors[] = $section_name . '_invalid_type';
            }
        }
        return $errors;
    }
}
