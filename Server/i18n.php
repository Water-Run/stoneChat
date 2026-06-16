<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/i18n.php
 *
 * Load language arrays from Server/langs/<code>.php and expose sc_t()
 * for translation lookup with a static in-memory cache so each file
 * is parsed at most once per request.
 *
 * Expected lang file format (Server/langs/<code>.php):
 *   <?php
 *   $entries = array('greeting' => 'Hello, world!');
 *   // -- or equivalently --
 *   // return array('greeting' => 'Hello, world!');
 *
 * Resolution order for the current language (sc_i18n_init):
 *   1. $_GET['lang']
 *   2. $_COOKIE['sc_lang']
 *   3. CONF.ini [i18n] default = ...
 *   4. The $default_lang argument passed to sc_i18n_init()
 *
 * Public helpers (sc_-prefixed, function_exists guarded):
 *   sc_i18n_supported_langs()           fixed list of supported codes
 *   sc_i18n_langs_dir()                 absolute path to Server/langs/
 *   sc_i18n_load($code)                 load (cached) translation table
 *   sc_load_lang($code)                 alias of sc_i18n_load
 *   sc_available_langs()                langs present on disk
 *   sc_i18n_current_lang($default)      resolve current code
 *   sc_i18n_init($default)              alias of sc_i18n_current_lang
 *   sc_t($key, $lang = '')              translate
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_i18n_supported_langs()
 *   Fixed list of language codes the application supports. */
if (!function_exists('sc_i18n_supported_langs')) {
    function sc_i18n_supported_langs() {
        return array('zh-CN', 'zh-TW', 'en', 'ja', 'ko', 'ru', 'fr', 'de');
    }
}

/* sc_i18n_langs_dir()
 *   Absolute path to Server/langs/. */
if (!function_exists('sc_i18n_langs_dir')) {
    function sc_i18n_langs_dir() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'langs';
    }
}

/* sc_i18n_load($lang_code)
 *   Load the translation table for a code; static cache keeps the
 *   file included at most once per request. */
if (!function_exists('sc_i18n_load')) {
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
        /* accept both `$entries = array(...)` and `return array(...)` */
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

/* sc_load_lang($lang_code)
 *   Alias of sc_i18n_load() for the short contract name. */
if (!function_exists('sc_load_lang')) {
    function sc_load_lang($lang_code) {
        return sc_i18n_load($lang_code);
    }
}

/* sc_available_langs()
 *   Scan Server/langs/ and return the codes that have a file.
 *   Sorted alphabetically. */
if (!function_exists('sc_available_langs')) {
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

/* sc_i18n_current_lang($default)
 *   Resolve the current language code. Memoized per request.
 *   Priority: $_GET['lang'] > $_COOKIE['sc_lang'] > CONF.ini
 *   [i18n] default > $default > 'en'. */
if (!function_exists('sc_i18n_current_lang')) {
    function sc_i18n_current_lang($default) {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $supported = sc_i18n_supported_langs();
        $lang = '';
        /* 1. URL parameter ?lang= */
        if (isset($_GET['lang']) && is_string($_GET['lang'])) {
            $lang = $_GET['lang'];
        }
        /* 2. Cookie sc_lang */
        if ($lang === '' && isset($_COOKIE['sc_lang'])
            && is_string($_COOKIE['sc_lang'])) {
            $lang = $_COOKIE['sc_lang'];
        }
        /* 3. CONF.ini [i18n] default, then $default arg */
        if ($lang === '') {
            $ini = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
                 . DIRECTORY_SEPARATOR . 'CONF.ini';
            $cfg = array();
            if (function_exists('sc_load_config')) {
                $cfg = sc_load_config($ini);
            } else {
                $parsed = @parse_ini_file($ini, true);
                if (is_array($parsed)) {
                    $cfg = $parsed;
                }
            }
            if (isset($cfg['i18n']['default'])
                && is_string($cfg['i18n']['default'])) {
                $lang = $cfg['i18n']['default'];
            } else {
                $lang = (string)$default;
            }
        }
        /* validate; final fallback is 'en'. */
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

/* sc_i18n_init($default_lang)
 *   Initialise the i18n subsystem and return the chosen code. */
if (!function_exists('sc_i18n_init')) {
    function sc_i18n_init($default_lang) {
        return sc_i18n_current_lang($default_lang);
    }
}

/* sc_t($key, $lang = '')
 *   Translate a key. Returns the key itself if not found. */
if (!function_exists('sc_t')) {
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
