<?php
/* tests/smoke/test_config_public.php
 *
 * Verifies GET /Server/api/config returns a sanitized public payload:
 *   - HTTP 200
 *   - title is "stoneChat"
 *   - default_lang is "en"
 *   - theme is "classic2001"
 *   - auth_enabled is true
 *   - providers has 1 entry (mock)
 *   - api_key is redacted to "****" (never echoed in cleartext) */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_config_public\n";
sc_test_http('GET', '/Server/api/config.php');
sc_test_assert_status(200, 'GET /api/config');
sc_test_assert_json_field('title',        'stoneChat',   'title is the configured value');
sc_test_assert_json_field('default_lang', 'en',          'default_lang is en');
sc_test_assert_json_field('theme',        'classic2001', 'theme is classic2001');
sc_test_assert_json_field('auth_enabled', 'true',        'auth_enabled is true');

$obj = json_decode($GLOBALS['SC_LAST_BODY'], true);
sc_test_assert_eq('1',    count($obj['providers']),             'providers list has 1 entry');
sc_test_assert_eq('mock', $obj['providers'][0]['id'],            'provider[0].id is "mock"');
/* The public /api/config endpoint strips sensitive fields (api_key,
 * api_base) entirely -- the masked redaction lives in
 * /api/providers. Verify the field is absent, not present. */
$has_key = (isset($obj['providers'][0]['api_key'])) ? 'present' : 'absent';
sc_test_assert_eq('absent', $has_key, 'api_key is stripped from /api/config');

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    echo "  last body: " . $GLOBALS['SC_LAST_BODY'] . "\n";
    exit(1);
}
exit(0);
