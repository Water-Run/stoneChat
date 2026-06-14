<?php
/**
 * stoneChat Server i18n module.
 *
 * Loads language arrays from Server/langs/<code>.php and exposes sc_t()
 * translation lookup with a static in-memory cache so each file is parsed
 * at most once per request.
 *
 * Expected lang file format (Server/langs/<code>.php):
 *   <?php
 *   $entries = array(
 *       'greeting' => 'Hello, world!',
 *       'farewell' => 'Goodbye.',
 *   );
 *   // -- or equivalently --
 *   // return array('greeting' => 'Hello, world!');
 *
 * Resolution order for the current language (sc_i18n_init):
 *   1. $_GET['lang']
 *   2. $_COOKIE['sc_lang']
 *   3. CONF.ini [i18n] default = ...
 *   4. The $default_lang argument passed to sc_i18n_init()
 *
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_i18n_supported_langs')) {
    /**
     * Return the list of language codes the application supports.
     *
     * The list is fixed; presence on disk is determined by sc_available_langs().
     *
     * @return array List of language codes (e.g. 'zh-CN', 'en').
     */
    function sc_i18n_supported_langs() {
        return array('zh-CN', 'zh-TW', 'en', 'ja', 'ko', 'ru', 'fr', 'de');
    }
}

if (!function_exists('sc_i18n_langs_dir')) {
    /**
     * Absolute path to the Server/langs/ directory containing per-language files.
     *
     * @return string Absolute path, with a trailing separator.
     */
    function sc_i18n_langs_dir() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'langs';
    }
}

if (!function_exists('sc_i18n_load')) {
    /**
     * Load the translation table for a language code.
     *
     * Uses a static cache so the file is included at most once per request.
     * Falls back to an empty array if the file is missing or malformed.
     *
     * @param string $lang_code Language code (e.g. 'en', 'zh-CN').
     * @return array Associative array of key=>string; empty array on miss.
     */
    function sc_i18n_load($lang_code) {
        static $cache = array();
        if (!is_string($lang_code) || $lang_code === '') {
            return array();
        }
        if (array_key_exists($lang_code, $cache)) {
            return $cache[$lang_code];
        }
        $file = sc_i18n_langs_dir() . DIRECTORY_SEPARATOR . $lang_code . '.php';
        if (!is_file($file) || !is_readable($file)) {
            $cache[$lang_code] = array();
            return array();
        }
        // Accept both `$entries = array(...)` and `return array(...)` styles.
        $entries = null;
        $result = include $file;
        if (is_array($result)) {
            $entries = $result;
        } elseif (!is_array($entries)) {
            $entries = array();
        }
        $cache[$lang_code] = $entries;
        return $entries;
    }
}

if (!function_exists('sc_load_lang')) {
    /**
     * Alias of sc_i18n_load() for callers using the short contract name.
     *
     * @param string $lang_code Language code.
     * @return array Associative array of translations; empty on miss.
     */
    function sc_load_lang($lang_code) {
        return sc_i18n_load($lang_code);
    }
}

if (!function_exists('sc_available_langs')) {
    /**
     * Scan Server/langs/ and return the language codes that have a file.
     *
     * A code is "available" iff <code>.php exists and is readable.
     * The result is sorted alphabetically.
     *
     * @return array Sorted list of language codes present on disk.
     */
    function sc_available_langs() {
        $dir = sc_i18n_langs_dir();
        if (!is_dir($dir)) {
            return array();
        }
        $dh = @opendir($dir);
        if ($dh === false) {
            return array();
        }
        $codes = array();
        while (($name = @readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $dot = strrpos($name, '.');
            if ($dot === false || $dot === 0) {
                continue;
            }
            if (strtolower(substr($name, $dot + 1)) !== 'php') {
                continue;
            }
            $codes[] = substr($name, 0, $dot);
        }
        @closedir($dh);
        sort($codes);
        return $codes;
    }
}

if (!function_exists('sc_i18n_current_lang')) {
    /**
     * Resolve the current language code using the documented priority.
     *
     * Memoized per request. Validates the result against the supported
     * list, finally falling back to 'en' if nothing matches.
     *
     * @param string $default Fallback when no higher-priority source is set.
     * @return string A language code from sc_i18n_supported_langs().
     */
    function sc_i18n_current_lang($default) {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $supported = sc_i18n_supported_langs();
        $lang = '';
        // 1. URL parameter ?lang=
        if (isset($_GET['lang']) && is_string($_GET['lang'])) {
            $lang = $_GET['lang'];
        }
        // 2. Cookie sc_lang
        if ($lang === '' && isset($_COOKIE['sc_lang']) && is_string($_COOKIE['sc_lang'])) {
            $lang = $_COOKIE['sc_lang'];
        }
        // 3. CONF.ini [i18n] default, then $default arg
        if ($lang === '') {
            $ini = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'CONF.ini';
            $cfg = array();
            if (function_exists('sc_load_config')) {
                $cfg = sc_load_config($ini);
            } else {
                $parsed = @parse_ini_file($ini, true);
                if (is_array($parsed)) {
                    $cfg = $parsed;
                }
            }
            if (isset($cfg['i18n']['default']) && is_string($cfg['i18n']['default'])) {
                $lang = $cfg['i18n']['default'];
            } else {
                $lang = (string)$default;
            }
        }
        // Validate; final fallback is 'en'.
        if (!in_array($lang, $supported)) {
            $lang = (string)$default;
        }
        if (!in_array($lang, $supported)) {
            $lang = 'en';
        }
        $resolved = $lang;
        return $resolved;
    }
}

if (!function_exists('sc_i18n_init')) {
    /**
     * Initialize the i18n subsystem and return the chosen language code.
     *
     * Safe to call more than once; the resolution is memoized.
     *
     * @param string $default_lang Fallback language code (e.g. 'en').
     * @return string The resolved current language code.
     */
    function sc_i18n_init($default_lang) {
        return sc_i18n_current_lang($default_lang);
    }
}

if (!function_exists('sc_t')) {
    /**
     * Translate a key.
     *
     * @param string $key  Translation key.
     * @param string $lang Optional language code. Empty = use current.
     * @return string Translated string, or the key itself if not found.
     */
    function sc_t($key, $lang = '') {
        if (!is_string($key) || $key === '') {
            return '';
        }
        if ($lang === '') {
            $lang = sc_i18n_current_lang('en');
        }
        $entries = sc_i18n_load($lang);
        if (isset($entries[$key]) && is_string($entries[$key])) {
            return $entries[$key];
        }
        return $key;
    }
}
