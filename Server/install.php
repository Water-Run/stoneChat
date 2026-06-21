<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/install.php
 *
 * Invoked by INSTALL.cmd via the PHP CLI to perform backend init
 * after the file copy step. Refuses non-CLI mode, validates the PHP
 * runtime, ensures runtime directories, validates CONF.ini, and
 * creates LOGIN.txt with restrictive permissions.
 *
 * Usage (CLI):
 *   php Server/install.php             # defaults to --all
 *   php Server/install.php --all
 *   php Server/install.php --init-config
 *   php Server/install.php --init-history
 *   php Server/install.php --init-langs
 *   php Server/install.php --init-login-log
 *   php Server/install.php --validate
 *
 * Output (one line per step):
 *   OK:<step>
 *   FAIL:<step>:<reason>
 *
 * Exit codes:
 *   0   every requested step succeeded
 *   1   one or more steps failed, or a pre-flight guard failed
 *
 * PHP 5.2 compatible (no closures, no [] array syntax, no namespaces,
 * no late static binding, no json_last_error).
 * ------------------------------------------------------------------------- */

/* ---- pre-flight guards (must run above the function defs) ------- */

/* Guard 1: refuse to run in non-CLI mode (web request, CGI, ...). */
if (!defined('PHP_SAPI') || (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server')) {
    echo 'FAIL:cli_only:must_run_from_command_line' . "\n";
    exit(1);
}

/* Guard 2: require PHP 5.4 or newer (RUN.bat uses php -S). */
if (!defined('PHP_VERSION')
    || !function_exists('version_compare')
    || !version_compare(PHP_VERSION, '5.4.0', '>=')) {
    $v = defined('PHP_VERSION') ? PHP_VERSION : 'unknown';
    /* dots are not safe inside the reason token; replace with '_'. */
    echo 'FAIL:php_version:php_' . str_replace('.', '_', (string)$v)
       . '_is_below_5_4' . "\n";
    exit(1);
}

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'boot_check.php';
if (function_exists('sc_strict_environment_check')) {
    sc_strict_environment_check();
}
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config.php';

/* ---- path helpers ----------------------------------------------- */

/* sc_install_project_root()
 *   Absolute path of the project root (parent of Server/). */
if (!function_exists('sc_install_project_root')) {
    function sc_install_project_root() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..';
    }
}

/* sc_install_ini_path()
 *   Absolute path to CONF.ini in the project root. */
if (!function_exists('sc_install_ini_path')) {
    function sc_install_ini_path() {
        return sc_install_project_root() . DIRECTORY_SEPARATOR . 'CONF.ini';
    }
}

/* sc_install_history_dir()
 *   Absolute path to the HISTORY/ runtime directory. */
if (!function_exists('sc_install_history_dir')) {
    function sc_install_history_dir() {
        return sc_install_project_root() . DIRECTORY_SEPARATOR . 'HISTORY';
    }
}

/* sc_install_langs_dir()
 *   Absolute path to Server/langs/. */
if (!function_exists('sc_install_langs_dir')) {
    function sc_install_langs_dir() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'langs';
    }
}

/* sc_install_login_log_path()
 *   Absolute path to LOGIN.txt in the project root. */
if (!function_exists('sc_install_login_log_path')) {
    function sc_install_login_log_path() {
        return sc_install_project_root() . DIRECTORY_SEPARATOR . 'LOGIN.txt';
    }
}

/* ---- platform helper -------------------------------------------- */

/* sc_install_is_windows()
 *   True when the host OS is Windows. */
if (!function_exists('sc_install_is_windows')) {
    function sc_install_is_windows() {
        $os = defined('PHP_OS') ? PHP_OS : '';
        return (strtoupper(substr((string)$os, 0, 3)) === 'WIN');
    }
}

/* ---- step primitives -------------------------------------------- */

/* sc_install_ensure_dir($path)
 *   Create a directory (recursively) if missing; otherwise no-op.
 *   Returns array(bool ok, string reason). */
if (!function_exists('sc_install_ensure_dir')) {
    function sc_install_ensure_dir($path) {
        if (!is_string($path) || $path === '') {
            return array(false, 'invalid_path');
        }
        if (is_dir($path)) {
            return array(true, 'already_exists');
        }
        /* mkdir() can succeed even when the final entry already
         * exists (race with another process), so verify after. */
        if (!@mkdir($path, 0777, true)) {
            if (!is_dir($path)) {
                return array(false, 'mkdir_failed');
            }
        }
        return array(true, 'created');
    }
}

/* sc_install_init_config()
 *   Create a stub CONF.ini if none exists. Never overwrites an
 *   existing config; returns 'already_exists' when one is present
 *   and readable. The stub contains the minimum keys the validator
 *   needs so the user can run --validate immediately. */
if (!function_exists('sc_install_init_config')) {
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
                  . "label = OpenAI\n"
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

/* sc_install_validate_config()
 *   Load CONF.ini and run sc_validate_config(). Error codes are
 *   joined with commas so the caller (INSTALL.cmd) can echo one
 *   line per failure. Secrets are never echoed. */
if (!function_exists('sc_install_validate_config')) {
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

/* sc_install_create_history_dir()
 *   Convenience wrapper: create HISTORY/ if missing. */
if (!function_exists('sc_install_create_history_dir')) {
    function sc_install_create_history_dir() {
        return sc_install_ensure_dir(sc_install_history_dir());
    }
}

/* sc_install_create_langs_dir()
 *   Convenience wrapper: create Server/langs/ if missing. */
if (!function_exists('sc_install_create_langs_dir')) {
    function sc_install_create_langs_dir() {
        return sc_install_ensure_dir(sc_install_langs_dir());
    }
}

/* sc_install_restrict_login_log($path)
 *   Apply restrictive permissions to an existing file.
 *
 *   Unix: chmod 0600 -- owner read/write only. Anything stricter
 *   (e.g. 0400) would prevent the web server from appending login
 *   attempts, which is the whole point of LOGIN.txt.
 *   Win:  icacls strips inherited ACEs and grants only the current
 *   user Read+Write. icacls may not be present on stock Windows XP;
 *   in that case the file is still reported as OK because the
 *   artefact (LOGIN.txt) itself is the most important outcome. */
if (!function_exists('sc_install_restrict_login_log')) {
    function sc_install_restrict_login_log($path) {
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return array(false, 'login_txt_missing');
        }
        if (sc_install_is_windows()) {
            /* single-quoted PHP literals keep the backslash-free
             * string clean; $path is already a Windows path with
             * backslashes that are literal in the resulting command. */
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

/* sc_install_create_login_log()
 *   Create an empty LOGIN.txt with restrictive permissions.
 *   Idempotent: existing files are re-permissioned in place. */
if (!function_exists('sc_install_create_login_log')) {
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

/* ---- CLI parsing & step dispatch -------------------------------- */

/* sc_install_parse_flags($argv)
 *   Extract the recognised flags. Unknown flags are silently
 *   ignored so the script stays forward-compatible. */
if (!function_exists('sc_install_parse_flags')) {
    function sc_install_parse_flags($argv) {
        if (!is_array($argv)) {
            return array();
        }
        $known = array(
            '--all',
            '--init-config',
            '--init-history',
            '--init-langs',
            '--init-login-log',
            '--validate',
        );
        $found = array();
        /* skip the script name in $argv[0] if present. */
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

/* sc_install_select_steps($flags)
 *   Map recognised flags to step names. With no recognised flag
 *   (e.g. INSTALL.cmd invokes `php install.php` with no args) we
 *   run every step ("do everything"). */
if (!function_exists('sc_install_select_steps')) {
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
        if (in_array('--init-login-log', $flags, true)) {
            $steps[] = 'login_log';
        }
        return $steps;
    }
}

/* sc_install_run_step($step)
 *   Execute one named step and return the (ok, reason) pair.
 *   Unknown step names are reported as failures so typos in
 *   sc_install_select_steps() never silently no-op. */
if (!function_exists('sc_install_run_step')) {
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

/* ---- main runner ------------------------------------------------ */

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
