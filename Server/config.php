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

if (!function_exists('sc_validate_config')) {
    /**
     * Validate a parsed stoneChat config array.
     *
     * Required keys:
     *   - [server].port   (non-empty)
     *   - [auth].password (non-empty)
     *
     * For each [Provider N] section:
     *   - api_key must be present and non-empty
     *   - type must be one of: "openai", "anthropic"
     *
     * Error messages are short codes; the secret values themselves are never echoed.
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
        // [auth].password must be present and non-empty.
        $has_pass = isset($cfg['auth']) && is_array($cfg['auth'])
                    && isset($cfg['auth']['password'])
                    && (string)$cfg['auth']['password'] !== '';
        if (!$has_pass) {
            $errors[] = 'missing_auth_password';
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
            }
            $type = isset($section_data['type']) ? strtolower(trim((string)$section_data['type'])) : '';
            if ($type === '' || !isset($supported_types[$type])) {
                $errors[] = $section_name . '_invalid_type';
            }
        }
        return $errors;
    }
}
