<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/boot_check.php
 *
 * Boot-time helpers called from front-facing entry points (router.php)
 * to decide whether the runtime is in a usable state. The file is
 * include-twice safe: every function is guarded with function_exists.
 *
 *   sc_boot_check_cache_dir()        HISTORY/ directory; created on demand
 *   sc_boot_check_modern_marker()    absolute path of .sc_os_build
 *   sc_is_modern_windows()           is the host Win10 1809+? (cached)
 *   sc_strict_environment_check()    hard-fail when runtime is not retro
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_boot_check_cache_dir()
 *   History sidecar directory (created on first use). */
if (!function_exists('sc_boot_check_cache_dir')) {
    function sc_boot_check_cache_dir() {
        $dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
             . DIRECTORY_SEPARATOR . 'HISTORY';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}

/* sc_boot_check_modern_marker()
 *   Absolute path of the per-host "modern Windows" build-number file. */
if (!function_exists('sc_boot_check_modern_marker')) {
    function sc_boot_check_modern_marker() {
        return sc_boot_check_cache_dir() . DIRECTORY_SEPARATOR
             . '.sc_os_build';
    }
}

/* sc_is_modern_windows()
 *   Is the host running Windows 10 1809 (build 17763) or newer?
 *
 *   Caching:
 *     1. per-request  -- static $cached (no IO on repeat)
 *     2. per-host     -- HISTORY/.sc_os_build
 *     3. cold start   -- shell_exec('ver'), parsed, persisted
 *
 *   Failure modes: non-Windows, no shell_exec, or unparseable 'ver'
 *   all return false. */
if (!function_exists('sc_is_modern_windows')) {
    function sc_is_modern_windows() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $marker = sc_boot_check_modern_marker();
        if (is_file($marker) && is_readable($marker)) {
            $raw = @file_get_contents($marker);
            if (is_string($raw)) {
                $build = (int)trim($raw);
                $cached = ($build >= 17763);
                return $cached;
            }
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $cached = false;
            return $cached;
        }

        $build = 0;
        $ver = @shell_exec('ver 2>NUL');
        if (is_string($ver) && $ver !== ''
            && preg_match('/\[\s*[^\d\]]*\d+\.\d+\.(\d+)(?:\.\d+)?\s*\]/',
                          $ver, $m)) {
            $build = (int)$m[1];
        }
        @file_put_contents($marker, (string)$build);
        $cached = ($build >= 17763);
        return $cached;
    }
}

/* sc_strict_environment_check()
 *   Hard-fail when the runtime is not the expected retro Windows env.
 *   Exits with HTTP 500 on failure. */
if (!function_exists('sc_strict_environment_check')) {
    function sc_strict_environment_check() {
        /* must be Windows */
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Error: stoneChat is only supported on Windows "
               . "operating systems (found: " . PHP_OS . ").\n";
            exit(1);
        }

        /* stunnel must exist at the configured path */
        $ini_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
                  . DIRECTORY_SEPARATOR . 'CONF.ini';
        $stunnel_path = 'C:\\Program Files\\stunnel\\bin\\stunnel.exe';
        if (is_file($ini_path)) {
            $raw = @parse_ini_file($ini_path, true);
            if (is_array($raw) && isset($raw['paths']['stunnel'])
                && $raw['paths']['stunnel'] !== '') {
                $stunnel_path = $raw['paths']['stunnel'];
            }
        }
        if (!is_file($stunnel_path)) {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Error: stunnel.exe not found at: " . $stunnel_path
               . ". stoneChat requires stunnel for HTTPS tunnel "
               . "proxying.\n"
               . "Download: https://www.stunnel.org/downloads.html\n";
            exit(1);
        }
    }
}
