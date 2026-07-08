<?php
/* tests/smoke/test_providers_list.php
 *
 * Verifies GET /Server/api/providers returns the mock provider with
 * the same redacted api_key contract as /api/config. */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_providers_list\n";
sc_test_http('GET', '/Server/api/providers.php');
sc_test_assert_status(200, 'GET /api/providers');
sc_test_assert_json_field('ok', 'true', 'ok is true');

$obj = json_decode($GLOBALS['SC_LAST_BODY'], true);
sc_test_assert_eq('1',      count($obj['providers']),           'providers count');
sc_test_assert_eq('mock',   $obj['providers'][0]['id'],         'provider[0].id is "mock"');
sc_test_assert_eq('openai', $obj['providers'][0]['type'],       'provider[0].type is "openai"');
sc_test_assert_eq('true',   $obj['providers'][0]['available'] ? 'true' : 'false',
    'provider[0].available is true');
sc_test_assert_eq('****',   $obj['providers'][0]['api_key'],    'provider[0].api_key is "****"');

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    echo "  last body: " . $GLOBALS['SC_LAST_BODY'] . "\n";
    exit(1);
}
exit(0);
