<?php
/**
 * stoneChat Server config loader.
 *
 * Loads CONF.ini and returns it as a nested array.
 * Returns an empty array on failure (missing file, parse error).
 *
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_load_config')) {
    /**
     * Load the stoneChat INI config file.
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
