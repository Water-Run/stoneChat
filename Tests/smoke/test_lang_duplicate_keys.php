<?php
/* tests/smoke/test_lang_duplicate_keys.php
 *
 * Regression test for the duplicate 'login.locked' key in the
 * shipped language files. PHP arrays silently allow duplicate
 * keys, so the only way to detect this is to walk each file and
 * count distinct vs total entries. */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_lang_duplicate_keys\n";

$langs = glob($GLOBALS['SC_PROJECT_DIR']
    . DIRECTORY_SEPARATOR . 'Server'
    . DIRECTORY_SEPARATOR . 'langs'
    . DIRECTORY_SEPARATOR . '*.php');
if (!is_array($langs)) {
    echo "  [FAIL] no language files found\n";
    $GLOBALS['SC_TEST_FAIL']++;
    exit(1);
}

foreach ($langs as $f) {
    $name = basename($f);
    $a = require $f;
    if (!is_array($a)) {
        echo "  [FAIL] $name: require did not return an array\n";
        $GLOBALS['SC_TEST_FAIL']++;
        continue;
    }
    $total  = count($a);
    $unique = count(array_unique(array_keys($a)));
    if ($total === $unique) {
        echo "  [ OK ] $name: no duplicate keys ($total entries)\n";
        $GLOBALS['SC_TEST_PASS']++;
    } else {
        echo "  [FAIL] $name: $total entries, $unique unique -- duplicate key present\n";
        $GLOBALS['SC_TEST_FAIL']++;
    }
}

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    exit(1);
}
exit(0);
