<?php
/* tests/smoke/test_stunnel_conf_path_normalization.php
 *
 * Regression test for the path-separator cleanup in
 * sc_generate_stunnel_conf(): the generated stunnel.conf must not
 * contain mixed "/" and "\" separators in its pid / CAfile lines. */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_stunnel_conf_path_normalization\n";

$conf_path = $GLOBALS['SC_PROJECT_DIR']
    . DIRECTORY_SEPARATOR . 'ModernNetwork'
    . DIRECTORY_SEPARATOR . 'stunnel.conf';
$pid_path  = $GLOBALS['SC_PROJECT_DIR']
    . DIRECTORY_SEPARATOR . 'ModernNetwork'
    . DIRECTORY_SEPARATOR . 'stunnel.pid';

@unlink($conf_path);
@unlink($pid_path);

require_once $GLOBALS['SC_PROJECT_DIR']
    . DIRECTORY_SEPARATOR . 'ModernNetwork' . DIRECTORY_SEPARATOR . 'proxy.php';

$tmp = tempnam(sys_get_temp_dir(), 'sc_stunnel_');
$ok  = sc_generate_stunnel_conf(
    $tmp,
    $tmp . '.pid',
    'C:/foo/bar/cacert.pem',
    8443,
    'api.example.com',
    443
);
$body = @file_get_contents($tmp);
@unlink($tmp);
@unlink($tmp . '.pid');

$pid_line = '';
$ca_line  = '';
foreach (preg_split("/\r?\n/", $body) as $line) {
    if (strpos($line, 'pid =')    === 0) { $pid_line = trim($line); }
    if (strpos($line, 'CAfile =') === 0) { $ca_line  = trim($line); }
}

if ($ok !== true) {
    echo "  [FAIL] sc_generate_stunnel_conf returned false\n";
    $GLOBALS['SC_TEST_FAIL']++;
} else {
    $has_forward  = (strpos($pid_line, '/')  !== false) || (strpos($ca_line, '/')  !== false);
    $has_backward = (strpos($pid_line, '\\') !== false) || (strpos($ca_line, '\\') !== false);
    $has_both_pid  = (strpos($pid_line, '/') !== false) && (strpos($pid_line, '\\') !== false);
    $has_both_ca   = (strpos($ca_line,  '/') !== false) && (strpos($ca_line,  '\\') !== false);
    $sep           = DIRECTORY_SEPARATOR;
    $pid_uses_sep  = ($pid_line !== '' && (strpos($pid_line, $sep) !== false) && !$has_both_pid);
    $ca_uses_sep   = ($ca_line  !== '' && (strpos($ca_line,  $sep) !== false) && !$has_both_ca);

    echo "  pid line : $pid_line\n";
    echo "  CAfile   : $ca_line\n";
    echo "  native separator: " . var_export($sep, true) . "\n";

    if ($has_both_pid || $has_both_ca) {
        echo "  [FAIL] mixed separators in generated stunnel.conf\n";
        $GLOBALS['SC_TEST_FAIL']++;
    } else {
        echo "  [ OK ] no mixed separators in generated stunnel.conf\n";
        $GLOBALS['SC_TEST_PASS']++;
    }
    if ($pid_uses_sep) {
        echo "  [ OK ] pid line uses native separator\n";
        $GLOBALS['SC_TEST_PASS']++;
    } else {
        echo "  [FAIL] pid line missing native separator\n";
        $GLOBALS['SC_TEST_FAIL']++;
    }
    if ($ca_uses_sep) {
        echo "  [ OK ] CAfile line uses native separator\n";
        $GLOBALS['SC_TEST_PASS']++;
    } else {
        echo "  [FAIL] CAfile line missing native separator\n";
        $GLOBALS['SC_TEST_FAIL']++;
    }
}

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    exit(1);
}
exit(0);
