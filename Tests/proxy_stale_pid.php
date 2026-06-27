<?php
/* Regression test for stale ModernNetwork/stunnel.pid handling.
 * PHP 5.2-compatible: no namespaces, closures, or short arrays. */

$failures = array();

function sc_test_fail($message) {
    global $failures;
    $failures[] = $message;
}

require_once dirname(__FILE__) . '/../ModernNetwork/proxy.php';

$pid_file = tempnam(sys_get_temp_dir(), 'scpid');
file_put_contents($pid_file, (string)getmypid());

$running = sc_stunnel_is_running($pid_file);
@unlink($pid_file);

if ($running !== false) {
    sc_test_fail('current PHP process PID should not be treated as stunnel');
}

if (!function_exists('sc_stunnel_pid_file_running')) {
    sc_test_fail('proxy should expose PID-file stunnel identity check');
} else {
    $pid_file = tempnam(sys_get_temp_dir(), 'scpid');
    file_put_contents($pid_file, (string)getmypid());
    $by_pid = sc_stunnel_pid_file_running($pid_file);
    @unlink($pid_file);
    if ($by_pid !== false) {
        sc_test_fail('PID-file helper should reject non-stunnel process even on Windows path');
    }
}

if (!empty($failures)) {
    echo "FAIL\n";
    foreach ($failures as $failure) {
        echo "- " . $failure . "\n";
    }
    exit(1);
}

echo "PASS\n";
exit(0);
