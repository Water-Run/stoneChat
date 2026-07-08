<?php
/* tests/smoke/test_auth_login_logout.php
 *
 * Verifies the password gate:
 *   - wrong password -> ok=false, error=invalid
 *   - correct password -> ok=true, sc_auth cookie issued
 *   - /api/history: 200 (logged in), 401 (logged out)
 *   - logout -> ok=true */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_auth_login_logout\n";

/* 1. Wrong password. */
sc_test_http('POST', '/Server/api/auth.php',
    json_encode(array('action' => 'login', 'password' => 'this_is_wrong')));
sc_test_assert_status(200, 'wrong password: HTTP 200 envelope');
sc_test_assert_json_field('ok',    'false',   'wrong password: ok=false');
sc_test_assert_json_field('error', 'invalid', 'wrong password: error=invalid');

/* 2. Correct password. */
sc_test_login();
sc_test_assert_status(200, 'login: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'login: ok=true');
if ($GLOBALS['SC_AUTH_COOKIE'] !== '') {
    echo "  [ OK ] sc_auth cookie issued\n";
    $GLOBALS['SC_TEST_PASS']++;
} else {
    echo "  [FAIL] sc_auth cookie missing\n";
    $GLOBALS['SC_TEST_FAIL']++;
}

/* 3. /api/history works while logged in. */
sc_test_http('GET', '/Server/api/history.php');
sc_test_assert_status(200, 'history: HTTP 200 when logged in');
$obj = json_decode($GLOBALS['SC_LAST_BODY'], true);
$has_key = (is_array($obj) && array_key_exists('conversations', $obj)) ? 'yes' : 'no';
sc_test_assert_eq('yes', $has_key, "history: body has 'conversations' key");

/* 4. Logout. */
sc_test_logout();
sc_test_assert_status(200, 'logout: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'logout: ok=true');

/* 5. After logout, history rejects. Clear the cookie jar and try. */
$GLOBALS['SC_AUTH_COOKIE'] = '';
@unlink($GLOBALS['SC_COOKIE_JAR']);
$GLOBALS['SC_COOKIE_JAR'] = tempnam(sys_get_temp_dir(), 'sc_cookies_');
sc_test_http('GET', '/Server/api/history.php');
sc_test_assert_status(401, 'history: HTTP 401 when logged out');
sc_test_assert_json_field('error', 'auth_required', 'history: error=auth_required');

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    echo "  last body: " . $GLOBALS['SC_LAST_BODY'] . "\n";
    exit(1);
}
exit(0);
