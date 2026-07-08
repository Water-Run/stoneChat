<?php
/* tests/smoke/run.php
 *
 * End-to-end smoke test for stoneChat.
 *
 * Usage:
 *   php tests/smoke/run.php                # use defaults
 *   SC_PORT=19999 php tests/smoke/run.php
 *   SC_PHP_BIN='C:\path\php.exe' php tests/smoke/run.php
 *   SC_TEST_PASSWORD=foo php tests/smoke/run.php
 *   SC_TEST_TIMEOUT=30 php tests/smoke/run.php
 *   SC_TOTAL_TIMEOUT=120 php tests/smoke/run.php
 *
 * What it does:
 *   1. Swap the project CONF.ini for a test-friendly one.
 *   2. Spawn `php -S` against Pages/router.php on $SC_PORT.
 *   3. Wait for the server to be ready.
 *   4. Run every test_*.php in lexical order with a per-child
 *      timeout (default 30s) and a total wall-clock cap (default
 *      120s). The first failure or timeout aborts the run.
 *   5. Kill the server and restore CONF.ini.
 *
 * The runner is itself a PHP 5.2-compatible script -- only the
 * the public API and the project's libraries are exercised. */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

/* Make sure we have fsockopen (we use it for HTTP I/O since
 * the curl extension is not always present in PHP 5.2 builds). */
if (!function_exists('fsockopen')) {
    fwrite(STDERR, "[FATAL] fsockopen() required for smoke tests\n");
    exit(2);
}

$SC_TEST_TIMEOUT    = getenv('SC_TEST_TIMEOUT')    ? (int)getenv('SC_TEST_TIMEOUT')    : 30;
$SC_TOTAL_TIMEOUT   = getenv('SC_TOTAL_TIMEOUT')   ? (int)getenv('SC_TOTAL_TIMEOUT')   : 120;
$SC_START_DEADLINE  = microtime(true) + $SC_TOTAL_TIMEOUT;

echo "================================================================\n";
echo "  stoneChat smoke test\n";
echo "================================================================\n";
echo "  host             : " . $GLOBALS['SC_HOST'] . "\n";
echo "  port             : " . $GLOBALS['SC_PORT'] . "\n";
echo "  php              : " . $GLOBALS['SC_PHP_BIN'] . "\n";
echo "  per-test timeout : {$SC_TEST_TIMEOUT}s\n";
echo "  total timeout    : {$SC_TOTAL_TIMEOUT}s\n";
echo "  project dir      : " . $GLOBALS['SC_PROJECT_DIR'] . "\n";
echo "\n";

/* Sanity: PHP must exist. */
if (!is_file($GLOBALS['SC_PHP_BIN'])) {
    fwrite(STDERR, "[FATAL] php binary not found: " . $GLOBALS['SC_PHP_BIN'] . "\n");
    exit(2);
}

/* Refuse to start if the chosen port is already serving. */
$probe_status = sc_test_wait_server($GLOBALS['SC_PORT'], 1);
if ($probe_status >= 200 && $probe_status < 400) {
    fwrite(STDERR, "[FATAL] port " . $GLOBALS['SC_PORT']
        . " is already serving stoneChat\n");
    exit(2);
}

/* Install the test CONF.ini. */
sc_test_swap_conf();

/* Register a shutdown function that always tears down the
 * background servers and restores the project CONF.ini, even
 * if the runner is killed by a signal, an uncaught exception,
 * or the total-timeout watchdog below. */
$SC_NORMAL_EXIT = 0;
register_shutdown_function('sc_runner_shutdown');

/* Spawn the server. */
$server_started = sc_test_start_server(
    $GLOBALS['SC_PORT'],
    $GLOBALS['SC_PROJECT_DIR'],
    $GLOBALS['SC_PROJECT_DIR'] . DIRECTORY_SEPARATOR . 'Pages'
        . DIRECTORY_SEPARATOR . 'router.php',
    /* mock = */ false
);
if (!$server_started) {
    fwrite(STDERR, "[FATAL] could not start stoneChat server\n");
    sc_test_stop_server();
    sc_test_restore_conf();
    exit(2);
}

echo "[1/3] starting stoneChat on http://" . $GLOBALS['SC_HOST']
    . ":" . $GLOBALS['SC_PORT'] . "\n";
$ready_status = sc_test_wait_server($GLOBALS['SC_PORT'], 10);
if ($ready_status < 200 || $ready_status >= 400) {
    fwrite(STDERR, "[FATAL] server did not respond within 10s (last status: $ready_status)\n");
    exit(3);
}
echo "      ready (status $ready_status)\n";

/* Run every test_*.php. The first failure or timeout aborts. */
echo "\n[2/3] running tests\n";
$tests = glob(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'test_*.php');
if (!is_array($tests) || empty($tests)) {
    fwrite(STDERR, "[FATAL] no test_*.php files found\n");
    exit(2);
}
sort($tests, SORT_STRING);

$pass = 0;
$fail = 0;
$timed_out = false;
foreach ($tests as $t) {
    $name = basename($t);
    if (microtime(true) >= $SC_START_DEADLINE) {
        echo "  [STOP] total wall-clock cap ({$SC_TOTAL_TIMEOUT}s) reached; aborting\n";
        $timed_out = true;
        $fail++;
        break;
    }
    echo "\n----------------------------------------------------------------\n";
    echo "  $name\n";
    echo "----------------------------------------------------------------\n";
    $GLOBALS['SC_AUTH_COOKIE'] = '';
    $child_output = '';
    $child_err    = '';
    $child_code   = -1;
    $tmp = tempnam(sys_get_temp_dir(), 'sc_runner_') . '.php';
    file_put_contents($tmp,
        "<?php\n"
        . "require_once " . var_export(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php', true) . ";\n"
        . "require_once " . var_export($t, true) . ";\n"
    );
    $cmd = $GLOBALS['SC_PHP_BIN'] . ' ' . escapeshellarg($tmp);
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        echo "  [FAIL] could not spawn child PHP for $name\n";
        $fail++;
        @unlink($tmp);
        continue;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    /* Watchdog: read both pipes with a per-test deadline. */
    $deadline = microtime(true) + $SC_TEST_TIMEOUT;
    $child_output = '';
    $child_err    = '';
    while (true) {
        $status = @proc_get_status($proc);
        $r = array($pipes[1], $pipes[2]);
        $w = null;
        $e = null;
        $sec = (int)($deadline - microtime(true));
        $usec = (int)(($deadline - microtime(true) - $sec) * 1000000);
        if ($sec < 0) { $sec = 0; $usec = 0; }
        $changed = @stream_select($r, $w, $e, $sec, $usec);
        if ($changed === false) {
            break; /* select failed; fall through to read loop */
        }
        if ($changed > 0) {
            foreach ($r as $stream) {
                $chunk = @fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $pipes[1]) { $child_output .= $chunk; }
                    else                       { $child_err    .= $chunk; }
                }
            }
        }
        if (microtime(true) >= $deadline) {
            echo "  [STOP] $name exceeded {$SC_TEST_TIMEOUT}s; killing child\n";
            $info = $status;
            if (!empty($info['pid'])) {
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    @exec('taskkill /F /T /PID ' . (int)$info['pid'] . ' 2>NUL');
                } else {
                    @exec('kill -9 ' . (int)$info['pid'] . ' 2>/dev/null');
                }
            }
            $timed_out = true;
            break;
        }
        if (!$status['running']) {
            /* Drain any remaining output. */
            $child_output .= (string)@stream_get_contents($pipes[1]);
            $child_err    .= (string)@stream_get_contents($pipes[2]);
            break;
        }
    }
    foreach ($pipes as $p) {
        if (is_resource($p)) { @fclose($p); }
    }
    $child_code = proc_close($proc);
    @unlink($tmp);
    echo $child_output;
    if (!empty($child_err)) {
        echo "  [WARN] child stderr:\n" . $child_err;
    }
    if ($timed_out) {
        $fail++;
        echo "  [STOP] $name failed (timeout); aborting\n";
        break;
    }
    if ($child_code !== 0) {
        $fail++;
        echo "  [STOP] $name failed (exit $child_code); aborting\n";
        break;
    }
    $pass++;
}

/* Tear down. */
$SC_NORMAL_EXIT = ($fail === 0) ? 0 : 1;
echo "\n[3/3] tearing down\n";
sc_test_stop_server();
sc_test_restore_conf();

echo "\n================================================================\n";
if ($fail === 0) {
    echo "  stoneChat smoke test: ALL " . $pass . " PASSED\n";
} else {
    echo "  stoneChat smoke test: " . $pass . " PASSED, " . $fail . " FAILED\n";
}
echo "================================================================\n";
exit($SC_NORMAL_EXIT);

/* sc_runner_shutdown()
 *   Last-resort teardown: runs even if the script aborts above. */
function sc_runner_shutdown() {
    @sc_test_stop_server();
    @sc_test_restore_conf();
}
