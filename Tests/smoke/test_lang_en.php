<?php
/* tests/smoke/test_lang_en.php
 *
 * Verifies GET /Server/api/lang.php?lang=en returns the bundled
 * English translations; bad codes return 404 unsupported_lang. */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_lang_en\n";
sc_test_http('GET', '/Server/api/lang.php?lang=en');
sc_test_assert_status(200, 'GET /api/lang?lang=en');
sc_test_assert_json_field('ok',   'true', 'ok is true');
sc_test_assert_json_field('lang', 'en',   'lang is en');
$obj = json_decode($GLOBALS['SC_LAST_BODY'], true);
sc_test_assert_eq('Sign in',
    isset($obj['entries']['login.title']) ? $obj['entries']['login.title'] : '',
    'entries.login.title is "Sign in"');

sc_test_http('GET', '/Server/api/lang.php?lang=zz');
sc_test_assert_status(404, 'GET /api/lang?lang=zz returns 404');
sc_test_assert_json_field('error', 'unsupported_lang', 'error is unsupported_lang');

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    echo "  last body: " . $GLOBALS['SC_LAST_BODY'] . "\n";
    exit(1);
}
exit(0);
