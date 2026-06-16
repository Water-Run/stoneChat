<?php
/**
 * stoneChat Server installer handler (Server/install.php).
 *
 * Invoked by INSTALL.bat via the PHP CLI to perform backend
 * initialization after the file copy step. Refuses to run in non-CLI
 * mode, validates the PHP runtime, ensures the runtime directories
 * exist, validates CONF.ini, and creates LOGIN.txt with restrictive
 * permissions.
 *
 * Usage (CLI):
 *   php Server/install.php             # defaults to --all
 *   php Server/install.php --all
 *   php Server/install.php --init-config
 *   php Server/install.php --init-history
 *   php Server/install.php --init-langs
 *   php Server/install.php --validate
 *
 * Output format (one line per step):
 *   OK:<step>
 *   FAIL:<step>:<reason>
 *
 * Exit codes:
 *   0   every requested step succeeded
 *   1   one or more steps failed, or pre-flight guard failed
 *
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no
 * namespaces, no late static binding, no json_last_error).
 */

// =============================================================
// Pre-flight guards (run before any helper is used; both exit on
// failure so they MUST sit above the function definitions).
// =============================================================

// Guard 1: refuse to run in non-CLI mode (web request, CGI, etc.).
if (!defined('PHP_SAPI') || (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server')) {
    echo 'FAIL:cli_only:must_run_from_command_line' . "\n";
    exit(1);
}

// Guard 2: require PHP 5.2 or newer.
if (!defined('PHP_VERSION')
    || !function_exists('version_compare')
    || !version_compare(PHP_VERSION, '5.2.0', '>=')) {
    $v = defined('PHP_VERSION') ? PHP_VERSION : 'unknown';
    // Dots are not safe inside the reason token; replace with '_'.
    echo 'FAIL:php_version:php_' . str_replace('.', '_', (string)$v)
       . '_is_below_5_2' . "\n";
    exit(1);
}

// Pull in the config loader/validator we depend on. config.php
// already guards its own functions with function_exists checks so
// repeated inclusion is a no-op.
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config.php';

// =============================================================
// Path helpers
// =============================================================

if (!function_exists('sc_install_project_root')) {
    /**
     * Absolute path of the project root (parent of Server/).
     *
     * @return string Absolute path with trailing separator.
     */
    function sc_install_project_root() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..';
    }
}

if (!function_exists('sc_install_ini_path')) {
    /**
     * Absolute path to CONF.ini in the project root.
     *
     * @return string Absolute path.
     */
    function sc_install_ini_path() {
        return sc_install_project_root() . DIRECTORY_SEPARATOR . 'CONF.ini';
    }
}

if (!function_exists('sc_install_history_dir')) {
    /**
     * Absolute path to the HISTORY/ runtime directory.
     *
     * @return string Absolute path.
     */
    function sc_install_history_dir() {
        return sc_install_project_root() . DIRECTORY_SEPARATOR . 'HISTORY';
    }
}

if (!function_exists('sc_install_langs_dir')) {
    /**
     * Absolute path to Server/langs/.
     *
     * @return string Absolute path.
     */
    function sc_install_langs_dir() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'langs';
    }
}

if (!function_exists('sc_install_login_log_path')) {
    /**
     * Absolute path to LOGIN.txt in the project root.
     *
     * @return string Absolute path.
     */
    function sc_install_login_log_path() {
        return sc_install_project_root() . DIRECTORY_SEPARATOR . 'LOGIN.txt';
    }
}

// =============================================================
// Platform helper
// =============================================================

if (!function_exists('sc_install_is_windows')) {
    /**
     * True when the host OS is Windows (XP, Vista, 7, 10, 11).
     *
     * Used to choose between chmod (Unix) and icacls (Windows) when
     * tightening permissions on LOGIN.txt.
     *
     * @return bool
     */
    function sc_install_is_windows() {
        $os = defined('PHP_OS') ? PHP_OS : '';
        return (strtoupper(substr((string)$os, 0, 3)) === 'WIN');
    }
}

// =============================================================
// Step primitives
// =============================================================

if (!function_exists('sc_install_ensure_dir')) {
    /**
     * Create a directory (recursively) if missing; otherwise no-op.
     *
     * Idempotent: when the directory already exists the function
     * returns true with reason 'already_exists'.
     *
     * @param string $path Absolute directory path.
     * @return array array(bool ok, string reason).
     */
    function sc_install_ensure_dir($path) {
        if (!is_string($path) || $path === '') {
            return array(false, 'invalid_path');
        }
        if (is_dir($path)) {
            return array(true, 'already_exists');
        }
        // mkdir() can succeed even when the final entry already
        // exists (race with another process), so verify after.
        if (!@mkdir($path, 0777, true)) {
            if (!is_dir($path)) {
                return array(false, 'mkdir_failed');
            }
        }
        return array(true, 'created');
    }
}

if (!function_exists('sc_install_init_config')) {
    /**
     * Create a stub CONF.ini if none exists.
     *
     * Never overwrites an existing config; returns 'already_exists'
     * when one is present and readable. The stub contains the
     * minimum keys sc_validate_config() looks for so the user can
     * run `--validate` immediately and see only the real errors.
     *
     * @return array array(bool ok, string reason).
     */
    function sc_install_init_config() {
        $ini = sc_install_ini_path();
        if (is_file($ini) && is_readable($ini)) {
            return array(true, 'already_exists');
        }
        $dir = dirname($ini);
        $mk = sc_install_ensure_dir($dir);
        if (!$mk[0]) {
            return array(false, 'parent_dir_' . $mk[1]);
        }
        $template = "; stoneChat CONF.ini\n"
                  . "; Created by Server/install.php. Replace every\n"
                  . "; placeholder before first run; see README.md.\n"
                  . "[server]\nport = 9999\n\n"
                  . "[auth]\npassword = YOUR_PASSWORD_HERE\n"
                  . "max_attempts = 5\n"
                  . "lockout_seconds = 300\n\n"
                  . "[ui]\ndefault_lang = en\n"
                  . "theme = classic2001\n\n"
                  . "[Provider 1]\n"
                  . "id = openai\n"
                  . "label = OpenAI (ChatGPT)\n"
                  . "type = openai\n"
                  . "api_base = https://api.openai.com/v1\n"
                  . "api_key = YOUR_OPENAI_API_KEY_HERE\n"
                  . "model = gpt-3.5-turbo\n";
        $bytes = @file_put_contents($ini, $template);
        if ($bytes === false) {
            return array(false, 'ini_write_failed');
        }
        return array(true, 'created');
    }
}

if (!function_exists('sc_install_validate_config')) {
    /**
     * Load CONF.ini and run sc_validate_config().
     *
     * Every error code returned by the validator is joined with
     * commas so the caller (INSTALL.bat) can echo one line per
     * failure. The validator never echoes secret values itself.
     *
     * @return array array(bool ok, string reason).
     */
    function sc_install_validate_config() {
        $ini = sc_install_ini_path();
        if (!is_file($ini) || !is_readable($ini)) {
            return array(false, 'conf_ini_missing_or_unreadable');
        }
        if (!function_exists('sc_load_config')) {
            return array(false, 'config_loader_missing');
        }
        if (!function_exists('sc_validate_config')) {
            return array(false, 'config_validator_missing');
        }
        $cfg = sc_load_config($ini);
        if (!is_array($cfg) || empty($cfg)) {
            return array(false, 'conf_ini_parse_failed');
        }
        $errors = sc_validate_config($cfg);
        if (!is_array($errors)) {
            return array(false, 'validator_returned_non_array');
        }
        if (!empty($errors)) {
            return array(false, implode(',', $errors));
        }
        return array(true, '');
    }
}

if (!function_exists('sc_install_create_history_dir')) {
    /**
     * Convenience wrapper: create HISTORY/ if missing.
     *
     * @return array array(bool ok, string reason).
     */
    function sc_install_create_history_dir() {
        return sc_install_ensure_dir(sc_install_history_dir());
    }
}

if (!function_exists('sc_install_create_langs_dir')) {
    /**
     * Convenience wrapper: create Server/langs/ if missing.
     *
     * @return array array(bool ok, string reason).
     */
    function sc_install_create_langs_dir() {
        return sc_install_ensure_dir(sc_install_langs_dir());
    }
}

if (!function_exists('sc_install_restrict_login_log')) {
    /**
     * Apply restrictive permissions to an existing file.
     *
     * Unix:  chmod 0600 -- owner read/write only. Anything stricter
     *        (e.g. 0400) would prevent the web server from appending
     *        login attempts, which is the whole point of LOGIN.txt.
     * Win:   icacls strips inherited ACEs and grants only the
     *        current user Read+Write. icacls may not be present on
     *        stock Windows XP; in that case the file is still
     *        reported as OK because the artefact itself (LOGIN.txt)
     *        is the most important outcome of this step.
     *
     * @param string $path Absolute file path.
     * @return array array(bool ok, string reason).
     */
    function sc_install_restrict_login_log($path) {
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return array(false, 'login_txt_missing');
        }
        if (sc_install_is_windows()) {
            // Single-quoted PHP literals keep the backslash-free
            // string clean; $path is already a Windows path with
            // backslashes that are literal in the resulting command.
            $cmd = 'icacls "' . $path . '" /inheritance:r '
                 . '/grant:r "%USERNAME%:(R,W)" 2>NUL';
            @shell_exec($cmd);
            return array(true, 'win_icacls_or_fallback');
        }
        if (!@chmod($path, 0600)) {
            return array(false, 'chmod_failed');
        }
        return array(true, '');
    }
}

if (!function_exists('sc_install_create_login_log')) {
    /**
     * Create an empty LOGIN.txt with restrictive permissions.
     *
     * Idempotent: when the file already exists we just (re-)apply
     * the restrictive ACL. The parent directory is created first so
     * the write never fails because of a missing intermediate dir.
     *
     * @return array array(bool ok, string reason).
     */
    function sc_install_create_login_log() {
        $path = sc_install_login_log_path();
        $dir = dirname($path);
        $mk = sc_install_ensure_dir($dir);
        if (!$mk[0]) {
            return array(false, 'parent_dir_' . $mk[1]);
        }
        if (!is_file($path)) {
            $bytes = @file_put_contents($path, '');
            if ($bytes === false) {
                return array(false, 'login_txt_write_failed');
            }
        }
        return sc_install_restrict_login_log($path);
    }
}

// =============================================================
// CLI parsing & step dispatch
// =============================================================

if (!function_exists('sc_install_parse_flags')) {
    /**
     * Extract the recognized flags from $argv.
     *
     * Unknown flags are silently ignored so the script remains
     * forward-compatible with future INSTALL.bat callers.
     *
     * @param array $argv Raw $argv from PHP.
     * @return array List of recognized flag strings.
     */
    function sc_install_parse_flags($argv) {
        if (!is_array($argv)) {
            return array();
        }
        $known = array(
            '--all',
            '--init-config',
            '--init-history',
            '--init-langs',
            '--validate',
        );
        $found = array();
        // Skip the script name in $argv[0] if present.
        $start = (isset($argv[0]) && is_string($argv[0])) ? 1 : 0;
        $n = count($argv);
        for ($i = $start; $i < $n; $i++) {
            $a = isset($argv[$i]) ? (string)$argv[$i] : '';
            if ($a === '') {
                continue;
            }
            if (in_array($a, $known, true)) {
                $found[] = $a;
            }
        }
        return $found;
    }
}

if (!function_exists('sc_install_select_steps')) {
    /**
     * Map recognized flags to a list of step names.
     *
     * Default behavior: when no recognized flag is present (e.g.
     * INSTALL.bat invokes `php install.php` with no arguments) we
     * run every step. This matches the "do everything" call site.
     *
     * @param array $flags Recognized flags from sc_install_parse_flags().
     * @return array List of step names to execute, in order.
     */
    function sc_install_select_steps($flags) {
        $all = array(
            'config_init',
            'config_validate',
            'history',
            'langs',
            'login_log',
        );
        if (!is_array($flags) || empty($flags)) {
            return $all;
        }
        if (in_array('--all', $flags, true)) {
            return $all;
        }
        $steps = array();
        if (in_array('--init-config', $flags, true)) {
            $steps[] = 'config_init';
        }
        if (in_array('--validate', $flags, true)) {
            $steps[] = 'config_validate';
        }
        if (in_array('--init-history', $flags, true)) {
            $steps[] = 'history';
        }
        if (in_array('--init-langs', $flags, true)) {
            $steps[] = 'langs';
        }
        return $steps;
    }
}

if (!function_exists('sc_install_run_step')) {
    /**
     * Execute one named step and return the (ok, reason) pair.
     *
     * Unknown step names are reported as failures so typos in
     * sc_install_select_steps() never silently no-op.
     *
     * @param string $step Step name.
     * @return array array(bool ok, string reason).
     */
    function sc_install_run_step($step) {
        switch ($step) {
            case 'config_init':
                return sc_install_init_config();
            case 'config_validate':
                return sc_install_validate_config();
            case 'history':
                return sc_install_create_history_dir();
            case 'langs':
                return sc_install_create_langs_dir();
            case 'login_log':
                return sc_install_create_login_log();
            default:
                return array(false, 'unknown_step');
        }
    }
}

// =============================================================
// Main runner
// =============================================================

$flags = (isset($argv) && is_array($argv))
       ? sc_install_parse_flags($argv)
       : array();
$steps = sc_install_select_steps($flags);

$failures = 0;
foreach ($steps as $step) {
    $result = sc_install_run_step($step);
    $ok = !empty($result[0]);
    $reason = isset($result[1]) ? (string)$result[1] : '';
    if ($ok) {
        echo 'OK:' . $step . "\n";
    } else {
        echo 'FAIL:' . $step . ':' . $reason . "\n";
        $failures++;
    }
}

if ($failures === 0) {
    echo 'INSTALL OK' . "\n";
    exit(0);
}
echo 'INSTALL FAILED:' . $failures . "\n";
exit(1);
