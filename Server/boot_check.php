<?php
/**
 * stoneChat Server boot-time checks.
 *
 * Helpers called from Pages/router.php (or any other front-facing entry
 * point) to decide whether the runtime is in a usable state. The file
 * is require-once safe: every function is wrapped in a function_exists
 * guard, so a duplicate include is a no-op.
 *
 * Public functions (all sc_-prefixed):
 *   sc_is_modern_windows()
 *       Detect whether the host is running Windows 10 1809 (build
 *       17763) or newer. The result is cached per-request (static var)
 *       and per-host (HISTORY/.sc_os_build file) so the relatively
 *       expensive `ver` shell-out runs at most once per process
 *       lifetime. Returns false on non-Windows or parse failure.
 *
 *   sc_boot_check_cache_dir()
 *       Absolute path of the directory where boot-check sidecar files
 *       (currently just .sc_os_build) live. Auto-created on first use.
 *
 *   sc_boot_check_modern_marker()
 *       The absolute path of the per-host modern-Windows marker file.
 *
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no
 * namespaces, no late static binding).
 */

if (!function_exists('sc_boot_check_cache_dir')) {
    /**
     * Absolute path of the directory holding boot-check sidecars.
     *
     * Created on first call so callers never have to mkdir before
     * reading a marker file. The directory lives next to the chat
     * history because that is the only per-host mutable state
     * stoneChat already manages.
     *
     * @return string Absolute path, with a trailing separator.
     */
    function sc_boot_check_cache_dir() {
        $dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
             . DIRECTORY_SEPARATOR . 'HISTORY';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}

if (!function_exists('sc_boot_check_modern_marker')) {
    /**
     * Absolute path of the per-host "modern Windows" build-number file.
     *
     * @return string Absolute path.
     */
    function sc_boot_check_modern_marker() {
        return sc_boot_check_cache_dir() . DIRECTORY_SEPARATOR
             . '.sc_os_build';
    }
}

if (!function_exists('sc_is_modern_windows')) {
    /**
     * Decide whether the host runs Windows 10 1809 (build 17763) or newer.
     *
     * Caching strategy:
     *   1. Per-request  : static $cached (in-memory, no IO on repeat).
     *   2. Per-host     : HISTORY/.sc_os_build (cross-request, survives
     *                     PHP's per-process lifetime).
     *   3. Cold start   : shell_exec('ver') once, parse build, persist.
     *
     * Failure modes:
     *   - Non-Windows host              -> false (no shell-out).
     *   - shell_exec disabled           -> false (silently).
     *   - `ver` output unparseable      -> false, caches "0".
     *   - Marker file unreadable        -> false, does not throw.
     *
     * @return bool true iff host is Windows with build >= 17763.
     */
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
            && preg_match('/\[\s*Version\s+\d+\.\d+\.(\d+)(?:\.\d+)?\s*\]/i',
                          $ver, $m)) {
            $build = (int)$m[1];
        }
        @file_put_contents($marker, (string)$build);
        $cached = ($build >= 17763);
        return $cached;
    }
}

if (!function_exists('sc_strict_environment_check')) {
    /**
     * Strictly verify that the current runtime environment is the expected retro Windows environment.
     * Throws an exception or exits with an error on failure.
     */
    function sc_strict_environment_check() {
        // 1. Must be Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Error: stoneChat is only supported on Windows operating systems (found: " . PHP_OS . ").\n";
            exit(1);
        }

        // 3. Stunnel must be present at the configured path
        $ini_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'CONF.ini';
        $stunnel_path = 'C:\\Program Files\\stunnel\\bin\\stunnel.exe';
        if (is_file($ini_path)) {
            $raw = @parse_ini_file($ini_path, true);
            if (is_array($raw) && isset($raw['paths']['stunnel']) && $raw['paths']['stunnel'] !== '') {
                $stunnel_path = $raw['paths']['stunnel'];
            }
        }
        if (!is_file($stunnel_path)) {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Error: stunnel.exe not found at: " . $stunnel_path . ". stoneChat requires stunnel for HTTPS tunnel proxying.\n";
            exit(1);
        }
    }
}
