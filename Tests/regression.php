<?php
/* stoneChat regression tests.
 * PHP 5.2-compatible: no namespaces, closures, or short arrays. */

$SC_TEST_FAILURES = array();
$SC_TEST_STREAM_CHUNKS = array();
$SC_TEST_TRANSPORT_BODIES = array();
$SC_TEST_TRANSPORT_CONFIGS = array();

function sc_test_fail($name, $message) {
    global $SC_TEST_FAILURES;
    $SC_TEST_FAILURES[] = $name . ': ' . $message;
}

function sc_test_assert_true($name, $condition, $message) {
    if (!$condition) {
        sc_test_fail($name, $message);
    }
}

function sc_test_assert_equal($name, $expected, $actual, $message) {
    if ($expected !== $actual) {
        sc_test_fail(
            $name,
            $message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

function sc_test_rmdir_recursive($dir) {
    if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
        return;
    }
    $dh = @opendir($dir);
    if ($dh === false) {
        return;
    }
    while (($name = @readdir($dh)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            sc_test_rmdir_recursive($path);
        } else {
            @unlink($path);
        }
    }
    @closedir($dh);
    @rmdir($dir);
}

/* Stub transport before loading Server/llm.php. The production file guards
 * sc_llm_send_via_tunnel with function_exists(), so this lets the test run
 * the real provider code without opening sockets. */
function sc_llm_send_via_tunnel($provider_config, $method, $path_suffix,
                                $headers, $body, $stream_callback = null) {
    global $SC_TEST_TRANSPORT_BODIES;
    global $SC_TEST_TRANSPORT_CONFIGS;
    $SC_TEST_TRANSPORT_CONFIGS[] = $provider_config;
    $SC_TEST_TRANSPORT_BODIES[] = $body;
    $payload = '';
    if (strpos((string)$body, 'SC_TITLE:') !== false) {
        $payload .= "data: {\"choices\":[{\"delta\":{\"content\":\"<think>draft</think>\\nSC_TITLE: XP Repair\"}}]}\n\n";
    } else {
        $payload .= "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n";
        $payload .= "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n";
    }
    $payload .= "data: [DONE]\n\n";
    if ($stream_callback !== null) {
        call_user_func($stream_callback, $payload);
    }
    return array('status' => 200, 'body' => $payload);
}

function sc_test_stream_callback($chunk, $event) {
    global $SC_TEST_STREAM_CHUNKS;
    $SC_TEST_STREAM_CHUNKS[] = $chunk;
}

function sc_test_router_probe($path, $modern) {
    $root = dirname(__FILE__) . '/..';
    $tmp = tempnam(sys_get_temp_dir(), 'scrt');
    $code = '<?php' . "\n"
          . 'chdir(' . var_export($root, true) . ');' . "\n"
          . 'function sc_strict_environment_check(){}' . "\n"
          . ($modern ? 'function sc_is_modern_windows(){return true;}' . "\n" : '')
          . '$_SERVER["REQUEST_URI"]=' . var_export($path, true) . ';'
          . "\n"
          . '$_SERVER["QUERY_STRING"]="";' . "\n"
          . 'ob_start();' . "\n"
          . '$r=require "Pages/router.php";' . "\n"
          . '$body=ob_get_clean();' . "\n"
          . 'echo "return=" . ($r ? "true" : "false") . "\n";'
          . "\n"
          . 'echo "body_len=" . strlen($body) . "\n";' . "\n"
          . 'echo "body=" . $body . "\n";' . "\n";
    @file_put_contents($tmp, $code);
    $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($tmp);
    $out = array();
    $code = 0;
    @exec($cmd, $out, $code);
    @unlink($tmp);
    return array('code' => $code, 'text' => implode("\n", $out));
}

require_once dirname(__FILE__) . '/../Server/config.php';
require_once dirname(__FILE__) . '/../Server/auth.php';
require_once dirname(__FILE__) . '/../Server/llm.php';
if (!function_exists('sc_strict_environment_check')) {
    function sc_strict_environment_check() {
    }
}
define('SC_API_PROVIDERS_NO_ENTRY', true);
require_once dirname(__FILE__) . '/../Server/api/providers.php';
define('SC_API_CHAT_NO_ENTRY', true);
require_once dirname(__FILE__) . '/../Server/api/chat.php';

$root = dirname(__FILE__) . '/..';

$test = 'configured users drive auth policy directly';
$auth_cfg = array(
    'User Admin' => array(
        'password' => 'admin123',
        'active' => 'true',
        'can_edit_config' => 'true',
        'excluded_models' => '',
        'default_lang' => 'en',
        'send_shortcut' => 'enter',
    ),
    'User Guest' => array(
        'password' => 'guestpass',
        'active' => 'true',
        'can_edit_config' => 'false',
        'excluded_models' => 'MiniMaxM3,MockLocal',
        'default_lang' => 'en',
        'send_shortcut' => 'shift_enter',
    ),
    'User Off' => array(
        'password' => 'offpass',
        'active' => 'false',
        'can_edit_config' => 'false',
        'excluded_models' => '',
    ),
);
$admin_user = sc_auth_find_user_by_password('admin123', $auth_cfg);
$guest_user = sc_auth_find_user_by_password('guestpass', $auth_cfg);
$off_user = sc_auth_find_user_by_password('offpass', $auth_cfg);
$bad_user = sc_auth_find_user_by_password('wrong', $auth_cfg);
sc_test_assert_equal($test, 'Admin',
                     isset($admin_user['username'])
                     ? $admin_user['username'] : '',
                     'admin password should select Admin user');
sc_test_assert_equal($test, 'Guest',
                     isset($guest_user['username'])
                     ? $guest_user['username'] : '',
                     'guest password should select Guest user');
sc_test_assert_true($test, empty($off_user['ok']),
                    'inactive user should not log in');
sc_test_assert_true($test, empty($bad_user['ok']),
                    'unknown password should not log in');
sc_test_assert_true($test, sc_auth_can_edit_config($auth_cfg, 'Admin'),
                    'Admin user should edit config');
sc_test_assert_true($test, !sc_auth_can_edit_config($auth_cfg, 'Guest'),
                    'Guest user should not edit config');
sc_test_assert_true($test, function_exists('sc_auth_user_send_shortcut'),
                    'auth should expose per-user send shortcut');
if (function_exists('sc_auth_user_send_shortcut')) {
    sc_test_assert_equal($test, 'enter',
                         sc_auth_user_send_shortcut($auth_cfg, 'Admin'),
                         'Admin should use its own send shortcut');
    sc_test_assert_equal($test, 'shift_enter',
                         sc_auth_user_send_shortcut($auth_cfg, 'Guest'),
                         'Guest should use its own send shortcut');
}
sc_test_assert_equal($test, 'en',
                     sc_auth_user_default_lang($auth_cfg, 'Admin', 'zh-CN'),
                     'Admin default language should be English');
sc_test_assert_equal($test, 'en',
                     sc_auth_user_default_lang($auth_cfg, 'Guest', 'zh-CN'),
                     'Guest default language should be English');
$admin_token = sc_auth_generate_token($auth_cfg, $admin_user);
$admin_ctx = sc_auth_token_context($admin_token, $auth_cfg);
sc_test_assert_equal($test, 'Admin',
                     isset($admin_ctx['username'])
                     ? $admin_ctx['username'] : '',
                     'token should preserve username');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$login_user = sc_auth_login('admin123', $auth_cfg);
$login_token = sc_auth_generate_token($auth_cfg, $login_user);
$login_ctx = sc_auth_token_context($login_token, $auth_cfg);
sc_test_assert_equal($test, 'Admin',
                     isset($login_ctx['username'])
                     ? $login_ctx['username'] : '',
                     'token built from login result should verify');

$test = 'auth tokens expire on the server side';
$ttl_cfg = $auth_cfg;
$ttl_cfg['auth'] = array('cookie_expires' => '3600');
$old_ts = time() - 7200;
$old_sig = sc_auth_sign($ttl_cfg, 'session', $old_ts . '|Admin');
$old_token = 'scv3:' . $old_ts . ':Admin:' . $old_sig;
$old_ctx = sc_auth_token_context($old_token, $ttl_cfg);
sc_test_assert_true($test, empty($old_ctx['ok']),
                    'token older than configured cookie lifetime should fail');
$fresh_token = sc_auth_generate_token($ttl_cfg, $admin_user);
$fresh_ctx = sc_auth_token_context($fresh_token, $ttl_cfg);
sc_test_assert_equal($test, 'Admin',
                     isset($fresh_ctx['username'])
                     ? $fresh_ctx['username'] : '',
                     'fresh token within configured lifetime should pass');

$test = 'auth tokens are signed with an install secret, not the password';
$forge_ts = time();
$password_sig = md5($forge_ts . '|Admin|' . $auth_cfg['User Admin']['password']);
$password_token = 'scv3:' . $forge_ts . ':Admin:' . $password_sig;
$password_ctx = sc_auth_token_context($password_token, $auth_cfg);
sc_test_assert_true($test, empty($password_ctx['ok']),
                    'legacy password-derived md5 token should not verify');
$real_token = sc_auth_generate_token($auth_cfg, $admin_user);
$real_parts = explode(':', $real_token);
sc_test_assert_true($test,
                    count($real_parts) === 4
                    && strlen($real_parts[3]) >= 64,
                    'new token signature should use a stronger HMAC digest');

$test = 'config editor CSRF helpers bind token to session and action';
sc_test_assert_true($test, function_exists('sc_auth_csrf_token'),
                    'auth module should expose CSRF token generation');
sc_test_assert_true($test, function_exists('sc_auth_csrf_verify'),
                    'auth module should expose CSRF token verification');
if (function_exists('sc_auth_csrf_token')
    && function_exists('sc_auth_csrf_verify')) {
    $csrf_token = sc_auth_csrf_token($admin_token, 'config_editor');
    sc_test_assert_true(
        $test,
        sc_auth_csrf_verify($admin_token, 'config_editor', $csrf_token),
        'matching token should verify'
    );
    sc_test_assert_true(
        $test,
        !sc_auth_csrf_verify($admin_token, 'other_action', $csrf_token),
        'same token should not verify for a different action'
    );
    sc_test_assert_true(
        $test,
        !sc_auth_csrf_verify($admin_token . '-other',
                             'config_editor', $csrf_token),
        'same token should not verify for a different session token'
    );
}

$test = 'API CSRF helpers issue action-specific tokens';
sc_test_assert_true($test, function_exists('sc_auth_csrf_tokens'),
                    'auth module should expose API CSRF token set helper');
if (function_exists('sc_auth_csrf_tokens')) {
    $csrf_actions = array(
        'auth:logout',
        'chat:send',
        'chat:name',
        'chat:regenerate',
        'chat:test',
        'config:reload',
        'history:new',
        'history:save',
        'history:rename',
        'history:set_system',
        'history:delete',
        'providers:test_all',
    );
    $api_tokens = sc_auth_csrf_tokens($admin_token, $csrf_actions);
    for ($i = 0; $i < count($csrf_actions); $i++) {
        $act = $csrf_actions[$i];
        sc_test_assert_true(
            $test,
            isset($api_tokens[$act])
            && sc_auth_csrf_verify($admin_token, $act, $api_tokens[$act]),
            'token should verify for ' . $act
        );
    }
    sc_test_assert_true(
        $test,
        !sc_auth_csrf_verify($admin_token, 'chat:send',
                             isset($api_tokens['history:delete'])
                             ? $api_tokens['history:delete'] : ''),
        'history delete token should not verify as chat send'
    );
}

$test = 'auth logging applies built-in local timezone without boot_check';
if (function_exists('date_default_timezone_set')
    && function_exists('date_default_timezone_get')) {
    @date_default_timezone_set('UTC');
    sc_auth_log_attempt('127.0.0.1', true, $auth_cfg);
    sc_test_assert_equal($test, 'Asia/Shanghai',
                         date_default_timezone_get(),
                         'auth log should use the built-in local timezone');
}

$test = 'history roots are isolated by user';
sc_test_assert_true($test, function_exists('sc_history_set_user'),
                    'history module should accept a current username');
if (function_exists('sc_history_set_user')
    && function_exists('sc_history_root')) {
    $hist_base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
               . 'stonechat-history-test-' . mt_rand(1000, 9999);
    $hist_cfg = array('ui' => array('history_dir' => $hist_base));
    sc_history_set_user('Admin');
    $admin_root = sc_history_root($hist_cfg);
    sc_history_set_user('Guest');
    $guest_root = sc_history_root($hist_cfg);
    sc_history_set_user('');
    sc_test_assert_true($test,
                        $admin_root !== '' && $guest_root !== ''
                        && strpos($admin_root, $hist_base) === 0
                        && strpos($guest_root, $hist_base) === 0
                        && $admin_root !== $guest_root
                        && strpos($admin_root, 'Admin') !== false
                        && strpos($guest_root, 'Guest') !== false,
                        'custom history_dir should be honored per user');
    sc_test_rmdir_recursive($hist_base);
}

$test = 'user model policy excludes configured model ids';
$provider_rows = array(
    array('id' => 'GPT55'),
    array('id' => 'MiniMaxM3'),
    array('id' => 'MockLocal'),
);
$guest_rows = sc_auth_filter_providers($provider_rows, $auth_cfg, 'Guest');
$admin_rows = sc_auth_filter_providers($provider_rows, $auth_cfg, 'Admin');
sc_test_assert_equal($test, 1, count($guest_rows),
                     'Guest should not see excluded models');
sc_test_assert_equal($test, 'GPT55',
                     isset($guest_rows[0]['id']) ? $guest_rows[0]['id'] : '',
                     'Guest remaining model should be GPT55');
sc_test_assert_equal($test, 3, count($admin_rows),
                     'Admin should see all models');

$test = 'config validation catches user model mistakes';
$bad_model_cfg = array(
    'server' => array('port' => '9999'),
    'User Admin' => array(
        'password' => 'admin-test-pass',
        'active' => 'maybe',
        'can_edit_config' => 'true',
        'excluded_models' => 'MiniMaxM3,MissingModel',
    ),
    'Model MiniMaxM3' => array(
        'active' => '1',
        'type' => 'openai',
        'api_base' => 'https://api.minimaxi.com/v1',
        'api_key' => 'real-key',
        'model' => 'MiniMax-M3',
    ),
    'Model BadModel' => array(
        'active' => '1',
        'type' => 'wrong',
        'api_base' => 'https://api.example.com/v1',
        'api_key' => 'YOUR_BAD_KEY_HERE',
        'model' => 'bad-model',
    ),
);
$bad_model_errors = sc_validate_config($bad_model_cfg);
sc_test_assert_true(
    $test,
    in_array('User Admin_active_invalid', $bad_model_errors, true),
    'invalid active value should be reported'
);
sc_test_assert_true(
    $test,
    in_array('User Admin_excluded_model_missing:MissingModel',
             $bad_model_errors, true),
    'unknown user excluded_models entry should be reported'
);
sc_test_assert_true(
    $test,
    in_array('Model BadModel_invalid_type', $bad_model_errors, true),
    'active model with bad type should be reported'
);
sc_test_assert_true(
    $test,
    in_array('Model BadModel_api_key_is_placeholder',
             $bad_model_errors, true),
    'active model with placeholder key should be reported'
);

$test = 'inactive placeholder models are ignored';
$inactive_provider_cfg = array(
    'server' => array('port' => '9999'),
    'User Admin' => array(
        'password' => 'admin-test-pass',
        'active' => '1',
        'can_edit_config' => '1',
        'excluded_models' => '',
    ),
    'Model GPT55' => array(
        'active' => '0',
        'type' => 'openai',
        'api_base' => 'https://api.openai.com/v1',
        'api_key' => 'YOUR_OPENAI_API_KEY_HERE',
        'model' => 'gpt-3.5-turbo',
    ),
);
$inactive_errors = sc_config_fatal_errors(
    sc_validate_config($inactive_provider_cfg)
);
sc_test_assert_equal($test, array(), $inactive_errors,
                     'inactive models should not block startup');

$test = 'config validation allows documented default passwords';
$weak_password_cfg = array(
    'server' => array('port' => '9999'),
    'User Admin' => array(
        'password' => 'admin123',
        'active' => 'true',
        'can_edit_config' => 'true',
        'excluded_models' => '',
    ),
    'User Guest' => array(
        'password' => '123456',
        'active' => 'true',
        'can_edit_config' => 'false',
        'excluded_models' => '',
    ),
);
$weak_errors = sc_config_fatal_errors(sc_validate_config($weak_password_cfg));
sc_test_assert_true(
    $test,
    !in_array('auth_user_password_is_placeholder', $weak_errors, true),
    'admin123/123456 should be allowed as default account passwords'
);

$test = 'sc_validate_path_resolve preserves absolute base';
$resolved = sc_validate_path_resolve(
    '../ModernNetwork/cacert.pem',
    dirname(__FILE__) . '/../Server'
);
$is_abs = false;
if (strlen($resolved) > 0
    && ($resolved[0] === '/' || $resolved[0] === '\\')) {
    $is_abs = true;
}
if (strlen($resolved) >= 2 && $resolved[1] === ':') {
    $is_abs = true;
}
sc_test_assert_true($test, $is_abs,
                    'resolved path should remain absolute');
sc_test_assert_true($test, is_file($resolved),
                    'resolved CA certificate path should exist');

$test = 'streaming OpenAI callback emits each SSE content chunk once';
$provider = array(
    'type'     => 'openai',
    'api_key'  => 'test-key',
    'model'    => 'test-model',
    'api_base' => 'http://localhost:9998/v1',
    'max_tokens' => '66',
);
$messages = array(array('role' => 'user', 'content' => 'hello'));
$result = sc_llm_openai(
    $provider, $messages, '', 'sc_test_stream_callback'
);

sc_test_assert_true($test, is_array($result) && !empty($result['ok']),
                    'provider call should parse successfully');
sc_test_assert_equal($test, 'Hello world',
                     isset($result['content']) ? $result['content'] : '',
                     'parsed content should match stream body');
sc_test_assert_equal($test, 2, count($SC_TEST_STREAM_CHUNKS),
                     'callback should receive exactly two content chunks');
sc_test_assert_equal($test, 'Hello world',
                     implode('', $SC_TEST_STREAM_CHUNKS),
                     'callback chunks should not be duplicated');
$sent_body = json_decode($SC_TEST_TRANSPORT_BODIES[0], true);
sc_test_assert_equal($test, 66,
                     isset($sent_body['max_tokens'])
                     ? $sent_body['max_tokens'] : null,
                     'provider max_tokens should reach transport JSON');

$test = 'OpenAI streaming parser handles cumulative MiniMax chunks';
$mini_chunks = array();
function sc_test_minimax_chunk($chunk, $event) {
    global $mini_chunks;
    $mini_chunks[] = $chunk;
}
$mini_sse = '';
$mini_sse .= "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n";
$mini_sse .= "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n";
$mini_sse .= "data: {\"choices\":[{\"delta\":{\"reasoning_details\":[{\"text\":\"Thinking\"}]}}]}\n\n";
$mini_sse .= "data: {\"choices\":[{\"delta\":{\"reasoning_details\":[{\"text\":\"Thinking done\"}]}}]}\n\n";
$mini_sse .= "data: {\"choices\":[{\"delta\":{\"content\":\"Hello world\"}}]}\n\n";
$mini_sse .= "data: [DONE]\n\n";
$mini_parsed = sc_llm_parse_openai_response(
    $mini_sse, 200, 'sc_test_minimax_chunk'
);
sc_test_assert_true($test, is_array($mini_parsed)
                    && !empty($mini_parsed['ok']),
                    'cumulative stream should parse successfully');
sc_test_assert_equal($test, 'HelloThinking done world',
                     isset($mini_parsed['content'])
                     ? $mini_parsed['content'] : '',
                     'parser should append only new cumulative suffixes');
sc_test_assert_equal($test, 'HelloThinking done world',
                     implode('', $mini_chunks),
                     'callback should receive only new cumulative suffixes');

$test = 'LLM request bodies honor configured max_tokens';
$openai_body = sc_llm_build_openai_body(
    'test-model',
    array(array('role' => 'user', 'content' => 'hello')),
    '',
    false,
    77
);
sc_test_assert_equal($test, 77,
                     isset($openai_body['max_tokens'])
                     ? $openai_body['max_tokens'] : null,
                     'OpenAI body should use caller max_tokens');
$anthropic_body = sc_llm_build_anthropic_body(
    'test-model',
    array(array('role' => 'user', 'content' => 'hello')),
    '',
    false,
    88
);
sc_test_assert_equal($test, 88,
                     isset($anthropic_body['max_tokens'])
                     ? $anthropic_body['max_tokens'] : null,
                     'Anthropic body should use caller max_tokens');

$test = 'model loader preserves optional model fields';
$tmp_ini = tempnam(sys_get_temp_dir(), 'scini');
file_put_contents(
    $tmp_ini,
    "[Model MockLocal]\n"
    . "active = 1\n"
    . "label = Mock Local\n"
    . "type = openai\n"
    . "api_base = http://localhost:9998/Server/api/mock_llm.php\n"
    . "api_key = mock_key\n"
    . "model = mock-gpt\n"
    . "stream = true\n"
    . "max_tokens = 256\n"
    . "timeout = 20\n"
);
$providers = sc_load_providers($tmp_ini);
@unlink($tmp_ini);
$mock_provider = null;
if (is_array($providers)) {
    foreach ($providers as $row) {
        if (is_array($row) && isset($row['id'])
            && $row['id'] === 'MockLocal') {
            $mock_provider = $row;
            break;
        }
    }
}
sc_test_assert_true($test, is_array($mock_provider),
                    'mock model should be present');
if (is_array($mock_provider)) {
    sc_test_assert_true(
        $test,
        isset($mock_provider['stream'])
        && (string)$mock_provider['stream'] === 'true',
        'mock model stream=true should be preserved'
    );
}

$test = 'config validation separates fatal startup errors';
$sample_errors = array(
    'auth_user_password_is_placeholder',
    'User Guest_excluded_model_missing:NoSuchModel',
    'paths_stunnel_missing',
    'Model GPT55_api_key_is_placeholder',
    'Model BadModel_invalid_type',
);
$fatal_errors = sc_config_fatal_errors($sample_errors);
sc_test_assert_equal($test, array(
                         'auth_user_password_is_placeholder',
                         'User Guest_excluded_model_missing:NoSuchModel',
                         'Model GPT55_api_key_is_placeholder',
                         'Model BadModel_invalid_type'
                     ),
                     $fatal_errors,
                     'auth/user/model errors should block startup validation');

$test = 'sample config documents default users and model units';
$sample_ini = @file_get_contents($root . '/CONF_SMP.INI');
sc_test_assert_true($test, is_string($sample_ini),
                    'CONF_SMP.INI should be readable');
if (is_string($sample_ini)) {
    sc_test_assert_true(
        $test,
        strpos($sample_ini, '[User Admin]') !== false
        && strpos($sample_ini, 'password = admin123') !== false,
        'sample config should include Admin user with default password'
    );
    sc_test_assert_true(
        $test,
        strpos($sample_ini, '[User Admin]') !== false
        && strpos($sample_ini, 'can_edit_config = true') !== false
        && strpos($sample_ini, 'default_lang = en') !== false
        && strpos($sample_ini, 'allow_online_editor = true') !== false,
        'sample Admin should be able to open the web config editor'
    );
    sc_test_assert_true(
        $test,
        strpos($sample_ini, '[User Guest]') !== false
        && strpos($sample_ini, 'password = 123456') !== false
        && strpos($sample_ini, 'can_edit_config = false') !== false,
        'sample Guest should exist with default password and no edit right'
    );
    sc_test_assert_true(
        $test,
        strpos($sample_ini, 'send_shortcut = enter') !== false
        && strpos($sample_ini, 'send_shortcut = shift_enter') !== false
        && strpos($sample_ini, '[ui]') !== false,
        'send shortcut should be configured on each user'
    );
    sc_test_assert_true(
        $test,
        strpos($sample_ini, '[Model MiniMaxM3]') !== false
        && strpos($sample_ini, '[Model GPT55]') !== false
        && strpos($sample_ini, 'excluded_models =') !== false
        && strpos($sample_ini, 'allow_models =') === false
        && strpos($sample_ini, 'allow_config =') === false
        && strpos($sample_ini, 'api_base =') !== false
        && strpos($sample_ini, '[Provider ') === false
        && strpos($sample_ini, 'model_id =') === false,
        'sample config should explain direct model units'
    );
}

$test = 'sample config keeps runtime knobs simple';
if (is_string($sample_ini)) {
    sc_test_assert_true(
        $test,
        strpos($sample_ini, 'auto_name') === false
        && strpos($sample_ini, 'font_profile') === false
        && strpos($sample_ini, 'timezone') === false
        && strpos($sample_ini, 'history_dir') === false,
        'sample config should not expose fine-grained runtime knobs'
    );
}

$test = 'provider normalize parses INI-style boolean strings';
$norm = sc_api_providers_normalize(
    array(
        'id' => 'bool-test',
        'label' => 'Bool Test',
        'type' => 'openai',
        'api_base' => 'http://127.0.0.1:11434/v1',
        'api_key' => 'test-key',
        'model' => 'test-model',
        'stream' => 'false',
        'max_tokens' => '55',
        'timeout' => '9',
    ),
    array('stream' => true, 'max_tokens' => 1024, 'timeout' => 60)
);
sc_test_assert_equal($test, false,
                     isset($norm['stream']) ? $norm['stream'] : null,
                     'stream=false should normalize to boolean false');
sc_test_assert_equal($test, 55,
                     isset($norm['max_tokens']) ? $norm['max_tokens'] : null,
                     'provider max_tokens should override defaults');
sc_test_assert_equal($test, 9,
                     isset($norm['timeout']) ? $norm['timeout'] : null,
                     'provider timeout should override defaults');

$test = 'provider test_all lowers socket timeout before ping';
$before_ping_configs = count($SC_TEST_TRANSPORT_CONFIGS);
$ping_result = sc_api_providers_ping_one(
    array(
        'id'       => 'slow-test',
        'label'    => 'Slow Test',
        'type'     => 'openai',
        'api_base' => 'http://127.0.0.1:11434/v1',
        'api_key'  => 'test-key',
        'model'    => 'test-model',
        'stream'   => true,
        'timeout'  => 60,
    ),
    3
);
$seen_ping_cfg = isset($SC_TEST_TRANSPORT_CONFIGS[$before_ping_configs])
                 ? $SC_TEST_TRANSPORT_CONFIGS[$before_ping_configs]
                 : array();
sc_test_assert_equal(
    $test,
    3,
    isset($seen_ping_cfg['timeout']) ? (int)$seen_ping_cfg['timeout'] : 0,
    'per-model ping should clamp provider timeout before transport'
);
sc_test_assert_true($test, is_array($ping_result),
                    'ping should still return a result envelope');

$test = 'model loader preserves explicit stream=false from INI';
$tmp_ini = tempnam(sys_get_temp_dir(), 'scini');
file_put_contents(
    $tmp_ini,
    "[llm]\nstream = true\n"
    . "[Model RawFalse]\n"
    . "label = RawFalse\n"
    . "type = openai\n"
    . "api_base = http://127.0.0.1:11434/v1\n"
    . "api_key = test-key\n"
    . "model = test-model\n"
    . "stream = false\n"
    . "max_tokens = 44\n"
    . "timeout = 8\n"
);
$raw_false = sc_load_providers($tmp_ini);
@unlink($tmp_ini);
sc_test_assert_true($test, is_array($raw_false) && count($raw_false) === 1,
                    'temporary model should load');
if (is_array($raw_false) && count($raw_false) === 1) {
    sc_test_assert_equal($test, 'false',
                         isset($raw_false[0]['stream'])
                         ? $raw_false[0]['stream'] : null,
                         'stream=false should survive parse_ini_file');
}

$test = 'LLM chat naming accepts history text fields';
$body_count = count($SC_TEST_TRANSPORT_BODIES);
$title = sc_llm_generate_chat_name(
    array(
        'type'     => 'openai',
        'api_key'  => 'test-key',
        'model'    => 'test-model',
        'api_base' => 'http://localhost:9998/v1',
    ),
    array(
        array('role' => 'user', 'text' => 'How to repair Windows XP?'),
        array('role' => 'assistant', 'text' => 'Use the recovery console.'),
    )
);
sc_test_assert_equal($test, 'XP Repair', $title,
                     'name generator should parse harness title only');
$name_body = isset($SC_TEST_TRANSPORT_BODIES[$body_count])
             ? json_decode($SC_TEST_TRANSPORT_BODIES[$body_count], true)
             : null;
$name_prompt = '';
if (is_array($name_body)
    && isset($name_body['messages'])
    && is_array($name_body['messages'])) {
    for ($i = 0; $i < count($name_body['messages']); $i++) {
        if (isset($name_body['messages'][$i]['content'])) {
            $name_prompt .= "\n" . (string)$name_body['messages'][$i]['content'];
        }
    }
}
sc_test_assert_true($test,
                    strpos($name_prompt, 'user: How to repair Windows XP?')
                    !== false
                    && strpos($name_prompt, 'SC_TITLE:') !== false
                    && strpos($name_prompt, 'fast') !== false,
                    'name prompt should include text-field user content and strict harness');
sc_test_assert_true($test,
                    strpos($title, '<think>') === false
                    && strpos($title, 'SC_TITLE:') === false,
                    'name parser should strip reasoning and harness tags');

$test = 'LLM parsers reject empty successful responses';
$empty_openai_json = sc_llm_parse_openai_response(
    '{"choices":[]}', 200, null
);
sc_test_assert_true(
    $test,
    is_array($empty_openai_json) && empty($empty_openai_json['ok'])
    && isset($empty_openai_json['error'])
    && $empty_openai_json['error'] === 'empty_response',
    'OpenAI JSON without content should be an error'
);
$empty_openai_sse = sc_llm_parse_openai_response(
    "data: [DONE]\n\n", 200, null
);
sc_test_assert_true(
    $test,
    is_array($empty_openai_sse) && empty($empty_openai_sse['ok'])
    && isset($empty_openai_sse['error'])
    && $empty_openai_sse['error'] === 'empty_response',
    'OpenAI SSE without content should be an error'
);
$empty_anthropic_json = sc_llm_parse_anthropic_response(
    '{"content":[]}', 200, null
);
sc_test_assert_true(
    $test,
    is_array($empty_anthropic_json) && empty($empty_anthropic_json['ok'])
    && isset($empty_anthropic_json['error'])
    && $empty_anthropic_json['error'] === 'empty_response',
    'Anthropic JSON without text content should be an error'
);
$empty_anthropic_sse = sc_llm_parse_anthropic_response(
    "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n", 200, null
);
sc_test_assert_true(
    $test,
    is_array($empty_anthropic_sse) && empty($empty_anthropic_sse['ok'])
    && isset($empty_anthropic_sse['error'])
    && $empty_anthropic_sse['error'] === 'empty_response',
    'Anthropic SSE without content deltas should be an error'
);

$test = 'chat stream path parses provider stream=false';
sc_test_assert_equal($test, false,
                     sc_api_chat_provider_stream(
                         array('stream' => 'false')
                     ),
                     'stream=false should disable upstream streaming');
sc_test_assert_equal($test, true,
                     sc_api_chat_provider_stream(
                         array('stream' => 'true')
                     ),
                     'stream=true should enable upstream streaming');

$test = 'chat send reports history write failures';
if (function_exists('sc_history_set_user')
    && function_exists('sc_history_chat_dir')) {
    $history_user = 'WriteFailTest' . mt_rand(1000, 9999);
    $chat_cfg = sc_load_config($root . '/CONF.ini');
    if (!is_array($chat_cfg)) {
        $chat_cfg = array();
    }
    sc_history_set_user($history_user);

    $chat_id = 'fulluser' . mt_rand(1000, 9999);
    $dir = sc_history_chat_dir($chat_id);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if ($dir !== '' && is_dir($dir)) {
        sc_history_save_meta($chat_id, array(
            'chat_id'     => $chat_id,
            'provider_id' => 'MockLocal',
            'model'       => 'mock-gpt',
            'name'        => '',
        ));
        for ($i = 1; $i <= 999; $i++) {
            @file_put_contents(
                $dir . DIRECTORY_SEPARATOR
                . 'user-' . sprintf('%03d', $i) . '.txt',
                'old'
            );
        }
        $send_result = sc_api_chat_handle_send(
            $chat_cfg,
            array('chat_id' => $chat_id,
                  'message' => 'new message',
                  'provider_id' => 'MockLocal')
        );
        sc_test_assert_true(
            $test,
            is_array($send_result) && empty($send_result['ok'])
            && isset($send_result['error'])
            && $send_result['error'] === 'history_write_failed',
            'user-message append failure should be returned to caller'
        );
    }

    $chat_id2 = 'fullassistant' . mt_rand(1000, 9999);
    $dir2 = sc_history_chat_dir($chat_id2);
    if ($dir2 !== '' && !is_dir($dir2)) {
        @mkdir($dir2, 0777, true);
    }
    if ($dir2 !== '' && is_dir($dir2)) {
        sc_history_save_meta($chat_id2, array(
            'chat_id'     => $chat_id2,
            'provider_id' => 'MockLocal',
            'model'       => 'mock-gpt',
            'name'        => '',
        ));
        for ($i = 1; $i <= 999; $i++) {
            @file_put_contents(
                $dir2 . DIRECTORY_SEPARATOR
                . 'assistant-' . sprintf('%03d', $i) . '.txt',
                'old'
            );
        }
        $send_result2 = sc_api_chat_handle_send(
            $chat_cfg,
            array('chat_id' => $chat_id2,
                  'message' => 'new message',
                  'provider_id' => 'MockLocal')
        );
        sc_test_assert_true(
            $test,
            is_array($send_result2) && empty($send_result2['ok'])
            && isset($send_result2['error'])
            && $send_result2['error'] === 'history_write_failed',
            'assistant-message append failure should be returned to caller'
        );
    }

    $cleanup_root = dirname($root . '/HISTORY/' . $history_user
                            . DIRECTORY_SEPARATOR . 'x');
    sc_history_set_user('');
    sc_test_rmdir_recursive($cleanup_root);
}

$test = 'manual title naming is a separate action';
$chat_php = @file_get_contents($root . '/Server/api/chat.php');
if (is_string($chat_php)) {
    sc_test_assert_true(
        $test,
        strpos($chat_php, "action === 'name'") !== false
        && strpos($chat_php, 'sc_api_chat_handle_name') !== false,
        'chat API should expose a separate title naming action'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_php, 'auto_name') === false
        && strpos($chat_php, 'not_first_turn') === false,
        'title naming should be manual, not auto-name first-turn logic'
    );
}

$test = 'router returns explicit 404 for Server library PHP files';
$router_text = @file_get_contents($root . '/Pages/router.php');
sc_test_assert_true($test, is_string($router_text),
                    'router should be readable');
if (is_string($router_text)) {
    sc_test_assert_true(
        $test,
        strpos($router_text, 'Cache-Control: no-store, no-cache, must-revalidate') !== false
        && strpos($router_text, "text/html; charset=UTF-8") !== false,
        'router should serve HTML with IE-safe no-cache headers'
    );
    sc_test_assert_true(
        $test,
        strpos($router_text, "text/javascript; charset=UTF-8") !== false
        && strpos($router_text, "text/css; charset=UTF-8") !== false
        && strpos($router_text, 'sc_router_no_cache') !== false,
        'router should also serve JS/CSS with IE-safe no-cache headers'
    );
}
$probe = sc_test_router_probe('/Server/config.php', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle the request itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');

$test = 'router returns explicit 404 for missing Pages files';
$probe = sc_test_router_probe('/Pages/missing.htm', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle the missing page itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');
$probe = sc_test_router_probe('/Pages/missing.htm', true);
sc_test_assert_equal($test, 0, $probe['code'],
                     'modern router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'modern redirect should not hide missing pages');

$test = 'router does not expose CONF.ini';
$probe = sc_test_router_probe('/CONF.ini', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle CONF.ini itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');

$test = 'router does not expose HISTORY runtime files';
$probe = sc_test_router_probe('/HISTORY/example/meta.txt', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle HISTORY itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');

$test = 'router blocks path traversal before static fallback';
$probe = sc_test_router_probe('/Pages/../CONF.ini', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute for Pages traversal');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle Pages traversal itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should 404 Pages traversal');
$probe = sc_test_router_probe('/Assets/../CONF.ini', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute for Assets traversal');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle Assets traversal itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should 404 Assets traversal');
$probe = sc_test_router_probe('/Server/api/../config.php', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute for Server traversal');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle Server traversal itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should 404 Server traversal');

$test = 'router does not expose ModernNetwork runtime tunnel files';
$runtime_files = array(
    'stunnel.conf' => 'client = yes',
    'stunnel.pid'  => '1234',
);
foreach ($runtime_files as $runtime_name => $runtime_body) {
    $runtime_path = $root . '/ModernNetwork/' . $runtime_name;
    @file_put_contents($runtime_path, $runtime_body);
    $probe = sc_test_router_probe('/ModernNetwork/' . $runtime_name, false);
    @unlink($runtime_path);
    sc_test_assert_equal($test, 0, $probe['code'],
                         'router probe should execute for ' . $runtime_name);
    sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                        'router should handle ' . $runtime_name . ' itself');
    sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                        'router should 404 ' . $runtime_name);
}

$test = 'router serves the bundled favicon';
$probe = sc_test_router_probe('/favicon.ico', false);
sc_test_assert_equal($test, 0, $probe['code'],
                     'favicon router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle favicon itself');
sc_test_assert_true($test, preg_match('/body_len=([1-9][0-9]*)/',
                                      $probe['text']) === 1,
                    'favicon response should contain icon bytes');
if (is_string($router_text)) {
    sc_test_assert_true(
        $test,
        strpos($router_text, 'Assets/logo.ico') !== false,
        'router should use the icon file that exists in Assets'
    );
}

$test = 'modern banner cookie plumbing is removed';
$router_php = @file_get_contents($root . '/Pages/router.php');
sc_test_assert_true($test, is_string($router_php),
                    'Pages/router.php should be readable');
if (is_string($router_php)) {
    sc_test_assert_true(
        $test,
        strpos($router_php, 'modern-banner.js') === false
        && strpos($router_php, 'sc_modern') === false,
        'router should not keep modern-banner-only cookie plumbing'
    );
}

$test = 'super-modern interlude rejects off-site next redirects';
$super_modern = @file_get_contents($root . '/Pages/super-modern.htm');
sc_test_assert_true($test, is_string($super_modern),
                    'Pages/super-modern.htm should be readable');
if (is_string($super_modern)) {
    sc_test_assert_true(
        $test,
        strpos($super_modern, 'function sc_safe_next') !== false,
        'super-modern.htm should validate next before redirecting'
    );
    sc_test_assert_true(
        $test,
        strpos($super_modern, 'sc_safe_next(sc_get_next())') !== false,
        'super-modern.htm should pass next through sc_safe_next before redirect'
    );
    sc_test_assert_true(
        $test,
        strpos($super_modern, '://') !== false
        && strpos($super_modern, 'charAt(0)') !== false,
        'super-modern.htm should reject absolute URLs and non-path next values'
    );
}

$test = 'super-modern interlude uses restrained modern copy';
sc_test_assert_true($test, is_string($super_modern),
                    'Pages/super-modern.htm should be readable');
if (is_string($super_modern)) {
    $banned = array('UC-style', 'shock-and-awe', '震惊', '惊呆', '太可怕',
                    '99%', 'AI味', '跪下');
    foreach ($banned as $word) {
        sc_test_assert_true(
            $test,
            strpos($super_modern, $word) === false,
            'super-modern.htm should not contain clickbait copy: ' . $word
        );
    }
    sc_test_assert_true(
        $test,
        strpos($super_modern, 'Compatibility handoff') !== false,
        'super-modern.htm should explain the handoff in restrained language'
    );
}

$test = 'super-modern interlude stays IE11 compatible';
sc_test_assert_true($test, is_string($super_modern),
                    'Pages/super-modern.htm should be readable');
if (is_string($super_modern)) {
    $ie11_banned = array('const ', 'let ', '=>', 'URLSearchParams',
                         'display: grid', 'place-items', 'var(--',
                         'backdrop-filter', 'text-wrap:', 'clamp(');
    foreach ($ie11_banned as $word) {
        sc_test_assert_true(
            $test,
            strpos($super_modern, $word) === false,
            'super-modern.htm should avoid IE11-incompatible syntax: ' . $word
        );
    }
    sc_test_assert_true(
        $test,
        strpos($super_modern, 'window.setTimeout(sc_redirect, 3000)')
        !== false,
        'super-modern.htm should close the interlude after 3 seconds'
    );
}

$test = 'online editor uses library includes and avoids short-open XML';
$editor_text = @file_get_contents($root . '/Pages/editor.php');
sc_test_assert_true($test, is_string($editor_text),
                    'Pages/editor.php should be readable');
if (is_string($editor_text)) {
    sc_test_assert_true(
        $test,
        strpos($editor_text, "Server/api/auth.php") === false
        && strpos($editor_text, "Server/api/config.php") === false,
        'editor.php should not include API entry points'
    );
    sc_test_assert_true(
        $test,
        strpos($editor_text, 'sc_auth_token_context') !== false
        && strpos($editor_text, 'sc_auth_can_edit_config') !== false,
        'editor.php should verify the auth cookie and user config right'
    );
    $csrf_pos = strpos($editor_text, 'sc_auth_csrf_verify');
    $write_pos = strpos($editor_text, 'file_put_contents($ini_path');
    sc_test_assert_true(
        $test,
        $csrf_pos !== false && $write_pos !== false && $csrf_pos < $write_pos
        && strpos($editor_text, 'sc_auth_csrf_token') !== false
        && strpos($editor_text, 'name="csrf_token"') !== false,
        'editor.php should verify a CSRF token before saving CONF.ini'
    );
    sc_test_assert_true(
        $test,
        strpos($editor_text, "\n<?xml") === false,
        'editor.php should not contain a raw XML declaration after PHP'
    );
    sc_test_assert_true(
        $test,
        strpos($editor_text, 'sc_editor_native_path') !== false
        && strpos($editor_text, 'ActiveXObject') !== false
        && strpos($editor_text, 'WScript.Shell') !== false
        && strpos($editor_text, 'notepad.exe') !== false
        && strpos($editor_text, 'action="editor.php"') !== false
        && strpos($editor_text, 'name="open_native"') !== false
        && strpos($editor_text, '<textarea name="config_content"') !== false,
        'editor.php should prefer IE ActiveX Notepad and keep web editor fallback'
    );
    sc_test_assert_true(
        $test,
        strpos($editor_text, 'popen(') === false
        && strpos($editor_text, 'exec($cmd') === false
        && strpos($editor_text, "new COM('WScript.Shell')") === false,
        'editor.php should not block PHP while trying to open Notepad'
    );
}

$test = 'install mechanism is removed';
sc_test_assert_true($test, !is_file($root . '/INSTALL.cmd'),
                    'INSTALL.cmd should not exist');
sc_test_assert_true($test, !is_file($root . '/UNINSTALL.cmd'),
                    'UNINSTALL.cmd should not exist');
sc_test_assert_true($test, !is_file($root . '/Server/install.php'),
                    'Server/install.php should not exist');

$test = 'launcher is RUN.cmd';
sc_test_assert_true($test, is_file($root . '/RUN.cmd'),
                    'RUN.cmd should exist at project root');
sc_test_assert_true($test, !is_file($root . '/RUN.bat'),
                    'RUN.bat should be removed after rename');

$test = 'launcher includes dependency download hints';
$php_url = 'https://windows.php.net/downloads/releases/archives/php-5.4.45-Win32-VC9-x86.zip';
$stunnel_url = 'https://www.stunnel.org/archive/5.x/stunnel-5.26-installer.exe';
$hint_files = array('RUN.cmd');
foreach ($hint_files as $hint_file) {
    $text = @file_get_contents($root . '/' . $hint_file);
    sc_test_assert_true($test, is_string($text),
                        $hint_file . ' should be readable');
    if (is_string($text)) {
        sc_test_assert_true($test, strpos($text, $php_url) !== false,
                            $hint_file . ' should show PHP download URL');
        sc_test_assert_true($test, strpos($text, $stunnel_url) !== false,
                            $hint_file . ' should show stunnel download URL');
    }
}

$test = 'launcher requires PHP built-in server runtime';
$run_text = @file_get_contents($root . '/RUN.cmd');
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, "version_compare(PHP_VERSION, '5.4.0'") !== false,
        'RUN.cmd should require PHP 5.4+ because it uses php -S'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'PHP 5.2') === false,
        'RUN.cmd should not advertise PHP 5.2 as runnable with php -S'
    );
}
$readme_text = @file_get_contents($root . '/README.org');
sc_test_assert_true($test, is_string($readme_text),
                    'README.org should be readable');
if (is_string($readme_text)) {
    sc_test_assert_true(
        $test,
        strpos($readme_text, 'PHP 5.4') !== false,
        'README.org should document PHP 5.4+ runtime requirement'
    );
    sc_test_assert_true(
        $test,
        strpos($readme_text, '#+TITLE: stoneChat') !== false,
        'README.org should use Org title metadata'
    );
}

$test = 'launcher reads server port without confusing proxy port';
$run_text = @file_get_contents($root . '/RUN.cmd');
sc_test_assert_true($test, is_string($run_text),
                    'RUN.cmd should be readable');
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'sc_load_config') !== false
        && strpos($run_text, "['server']['port']") !== false,
        'RUN.cmd should read [server] port through the PHP config loader'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'if /i "!_k!"=="port"') === false,
        'RUN.cmd should not scan every INI port key'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'sc_config_fatal_errors') !== false,
        'RUN.cmd should only fail CONF validation on fatal config errors'
    );
}

$test = 'launcher guards Windows build before numeric compare';
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'findstr /R "^[0-9][0-9]*$"') !== false,
        'RUN.cmd should verify WIN_BUILD is numeric before GEQ'
    );
}

$test = 'launcher rejects exclamation-mark paths before delayed expansion';
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'setlocal DisableDelayedExpansion') !== false,
        'RUN.cmd should start with delayed expansion disabled'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'setlocal EnableDelayedExpansion') !== false,
        'RUN.cmd should enable delayed expansion only after path checks'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'Current stoneChat path contains an exclamation mark') !== false,
        'RUN.cmd should reject ! in its runtime path'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, ':!=') === false,
        'RUN.cmd should not use CMD substring replacement to detect !'
    );
}

$test = 'launcher quotes and validates stunnel path before use';
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'Stunnel path contains an exclamation mark')
        !== false,
        'RUN.cmd should reject ! in stunnel path'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'if exist "!STUNNEL_PATH!"') !== false,
        'RUN.cmd should use delayed quoted stunnel path in existence check'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, '$c=sc_load_config(\'CONF.ini\');echo isset($c[\'paths\'][\'stunnel\'])')
        !== false,
        'RUN.cmd should read stunnel path through the PHP config loader when PHP is available'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, '[ OK ] stunnel found: "!STUNNEL_PATH!"')
        !== false
        && strpos($run_text, '[FAIL] stunnel.exe not found at: "!STUNNEL_PATH!"')
        !== false,
        'RUN.cmd should quote stunnel path in output lines'
    );
}

$test = 'launcher reports taskkill failures when freeing port';
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'taskkill /F /T /PID') !== false,
        'RUN.cmd should kill the whole process tree for a busy port'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'TASKKILL_LOG') !== false
        && strpos($run_text, 'type "!TASKKILL_LOG!"') !== false,
        'RUN.cmd should show taskkill output instead of hiding failures'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'PID 0') !== false
        || strpos($run_text, 'PID 4') !== false,
        'RUN.cmd should explain protected system PIDs'
    );
}

$test = 'config template does not promise unsupported local override files';
$conf_text = @file_get_contents($root . '/CONF.ini');
sc_test_assert_true($test, is_string($conf_text),
                    'CONF.ini should be readable');
if (is_string($conf_text)) {
    sc_test_assert_true(
        $test,
        strpos($conf_text, 'CONF.local.ini') === false
        && strpos($conf_text, 'env override') === false,
        'CONF.ini comments should only describe implemented config loading'
    );
}

$test = 'direct Pages/index.php fallback uses environment guard';
$index_php = @file_get_contents($root . '/Pages/index.php');
sc_test_assert_true($test, is_string($index_php),
                    'Pages/index.php should be readable');
if (is_string($index_php)) {
    sc_test_assert_true($test, strpos($index_php, 'boot_check.php') !== false,
                        'index.php should include boot_check.php');
    sc_test_assert_true(
        $test,
        strpos($index_php, 'sc_strict_environment_check') !== false,
        'index.php should call sc_strict_environment_check'
    );
}

$test = 'runtime environment guard shows stunnel download hint';
$boot_check = @file_get_contents($root . '/Server/boot_check.php');
sc_test_assert_true($test, is_string($boot_check),
                    'Server/boot_check.php should be readable');
if (is_string($boot_check)) {
    sc_test_assert_true(
        $test,
        strpos($boot_check, 'https://www.stunnel.org/archive/5.x/stunnel-5.26-installer.exe') !== false,
        'runtime stunnel error should include the stunnel download URL'
    );
    sc_test_assert_true(
        $test,
        strpos($boot_check, 'Version\\s+') === false
        && strpos($boot_check, '[^\\d\\]]*\\d+\\.\\d+\\.(\\d+)') !== false,
        'modern Windows build parsing should not depend on English ver output'
    );
}

$test = 'streaming chat handlers emit errors on LLM dispatch failure';
sc_test_assert_true($test, is_string($chat_php),
                    'Server/api/chat.php should be readable');
if (is_string($chat_php)) {
    sc_test_assert_true(
        $test,
        strpos($chat_php, 'sc_api_chat_stream_result_error') !== false,
        'stream handlers should share dispatch-result error mapping'
    );
    sc_test_assert_true(
        $test,
        substr_count($chat_php, 'sc_api_chat_stream_result_error($result)') >= 2,
        'both send_stream and regenerate_stream should check dispatch result'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_php, 'function sc_api_chat_stream_headers') !== false
        && substr_count($chat_php, 'sc_api_chat_stream_headers();') >= 2,
        'stream handlers should set SSE headers before early errors'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_php, '$assistant === \'\' && $stream_err !== \'\'') === false,
        'stream handlers should surface dispatch errors even after partial chunks'
    );
}

$test = 'front-end documents slogan and avoids stale delete wording';
$app_js = @file_get_contents($root . '/Pages/js/app.js');
$api_js = @file_get_contents($root . '/Pages/js/api.js');
$chat_js = @file_get_contents($root . '/Pages/js/chat.js');
$i18n_js = @file_get_contents($root . '/Pages/js/i18n.js');
$chat_htm = @file_get_contents($root . '/Pages/chat.htm');
$index_htm = @file_get_contents($root . '/Pages/index.htm');
$auth_api_php = @file_get_contents($root . '/Server/api/auth.php');
$config_api_php = @file_get_contents($root . '/Server/api/config.php');
$server_i18n_php = @file_get_contents($root . '/Server/i18n.php');
$main_css = @file_get_contents($root . '/Pages/css/main.css');
$readme_text = @file_get_contents($root . '/README.org');
sc_test_assert_true($test,
                    is_string($app_js) && is_string($chat_js)
                    && is_string($i18n_js)
                    && is_string($chat_htm) && is_string($index_htm)
                    && is_string($main_css) && is_string($auth_api_php)
                    && is_string($config_api_php)
                    && is_string($server_i18n_php)
                    && is_string($readme_text),
                    'front-end and README.org files should be readable');
if (is_string($app_js) && is_string($chat_js)
    && is_string($chat_htm) && is_string($index_htm)
    && is_string($main_css)
    && is_string($readme_text)) {
    sc_test_assert_true(
        $test,
        strpos($app_js, 'Recycle Bin') === false
        && strpos($app_js, 'On Windows it will') === false,
        'delete confirmation should not mention recycle-bin wording'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_htm, 'sc-history-search') !== false
        && strpos($chat_htm, 'sc-about-btn') !== false,
        'chat page should expose history search and About link'
    );
    sc_test_assert_true(
        $test,
        strpos($app_js, 'Author: WaterRun') !== false
        && strpos($app_js, 'a caveman peeking at modern technology') !== false
        && strpos($readme_text, '#+AUTHOR: WaterRun') !== false
        && strpos($readme_text, 'a caveman peeking at modern technology') !== false,
        'About dialog and README.org should keep author and slogan'
    );
    sc_test_assert_true(
        $test,
        strpos($app_js, 'Protocol:') === false
        && strpos($app_js, 'Modern Windows') !== false
        && strpos($app_js, 'Modern Browser') !== false
        && strpos($app_js, 'Time Zone') !== false,
        'About dialog should show runtime environment, not protocol text'
    );
    sc_test_assert_true(
        $test,
        strpos($app_js, "window.open('editor.php?open_native=1'") !== false
        && strpos($app_js, 'currentConfig.can_edit_config') !== false
        && strpos($app_js, 'sc-open-config-btn') !== false,
        'Edit config entry should be hidden unless config says the user can edit'
    );
    sc_test_assert_true(
        $test,
        strpos($api_js, 'sc_cacheBustUrl') !== false
        && strpos($api_js, "setRequestHeader('Cache-Control', 'no-cache')") !== false
        && strpos($config_api_php, 'Cache-Control: no-store') !== false,
        'IE-safe GET requests should bypass stale cached config'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_htm, 'name-button') !== false
        && strpos($chat_htm, 'chat.generateTitle') !== false
        && strpos($chat_htm, 'regenerate-button') < strpos($chat_htm, 'name-button')
        && strpos($chat_js, 'sc_generateTitle') !== false
        && strpos($chat_js, 'nameChatAsync') !== false,
        'front-end should expose manual async title generation beside Regenerate'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_js, 'sc_scheduleAutoName') === false
        && strpos($chat_js, 'auto_name') === false
        && strpos($chat_js, 'setTimeout(function ()') === false,
        'front-end should not auto-name conversations'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_js, 'SC.Api.nameChat(') === false,
        'front-end naming must not use synchronous XHR during streaming'
    );
    sc_test_assert_true(
        $test,
        preg_match('/(^|[^A-Za-z0-9_])nameChat[ \t]*:/', $api_js) !== 1,
        'API facade must not expose synchronous first-turn naming'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_js, 'chat.sendHintEnter') !== false
        && strpos($chat_js, 'chat.charCount') !== false
        && strpos($chat_js, 'chat.wait') !== false,
        'input toolbar text should use i18n keys'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_htm, 'i18n.init(supportedLangs, defaultLang);') !== false
        && strpos($chat_htm, 'i18n.init(supportedLangs, defaultLang, true)') === false,
        'chat language default should not lock out the top language buttons'
    );
    sc_test_assert_true(
        $test,
        strpos($index_htm, 'chat.htm?lang=') !== false
        && strpos($auth_api_php, 'sc_auth_user_default_lang') !== false,
        'login should enter chat with the current user default language'
    );
    sc_test_assert_true(
        $test,
        strpos($index_htm, 'sc_lang') === false
        && strpos($chat_htm, 'sc_lang') === false
        && strpos($i18n_js, 'sc_lang') === false
        && strpos($server_i18n_php, 'sc_lang') === false
        && strpos($readme_text, 'sc_lang') === false,
        'front-end language selection should be URL-based, not cookie state'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_htm, 'js/app.js?v=') !== false
        && strpos($index_htm, 'js/api.js?v=') !== false
        && strpos($chat_htm, 'css/main.css?v=') !== false,
        'entry pages should version CSS and JS to avoid stale browser cache'
    );
    sc_test_assert_true(
        $test,
        strpos($chat_htm, 'modern-banner.js') === false
        && strpos($index_htm, 'modern-banner.js') === false,
        'classic pages should not load the modern banner overlay'
    );
    sc_test_assert_true(
        $test,
        !is_file($root . '/Pages/js/modern-banner.js')
        && !is_file($root . '/Tests/modern_banner_cookie.js'),
        'modern-banner implementation and old cookie test should be removed'
    );
    sc_test_assert_true(
        $test,
        strpos($api_js, 'encodeURIComponent(chatId)') !== false,
        'history id query parameters should be URL-encoded'
    );
    sc_test_assert_true(
        $test,
        strpos($api_js, 'csrf_token') !== false
        && strpos($auth_api_php, 'csrf') !== false,
        'front-end and auth API should exchange CSRF tokens'
    );
    sc_test_assert_true(
        $test,
        strpos($main_css, 'input[type') === false
        && strpos($main_css, 'box-sizing') === false
        && strpos($main_css, 'border-radius') === false
        && strpos($main_css, 'box-shadow') === false
        && strpos($main_css, '@keyframes') === false
        && strpos($main_css, 'linear-gradient') === false,
        'classic CSS should stay within XP-era selector/property support'
    );
    sc_test_assert_true(
        $test,
        strpos($app_js, 'applyFontProfile') === false
        && strpos($main_css, 'body.font-') === false
        && strpos($api_js, 'font_profile') === false,
        'front-end should not carry a font profile mechanism'
    );
    sc_test_assert_true(
        $test,
        strpos($readme_text, '#+begin_example') !== false
        && strpos($readme_text, '[X]') !== false
        && strpos($readme_text, 'Font profiles') === false
        && strpos($readme_text, '| ~font_profile~') === false,
        'README.org should use Org-specific syntax without font profile knobs'
    );
    sc_test_assert_true(
        $test,
        strpos($readme_text, 'admin123') !== false
        && strpos($readme_text, '123456') !== false
        && strpos($readme_text, 'guestpass') === false,
        'README.org should document the current default passwords only'
    );
}

$test = 'ModernNetwork writes full HTTP request buffers';
$proxy_php = @file_get_contents($root . '/ModernNetwork/proxy.php');
sc_test_assert_true($test, is_string($proxy_php),
                    'ModernNetwork/proxy.php should be readable');
if (is_string($proxy_php)) {
    sc_test_assert_true(
        $test,
        strpos($proxy_php, '$written') !== false
        && strpos($proxy_php, 'strlen($req)') !== false
        && strpos($proxy_php, 'substr($req, $written)') !== false,
        'proxy should loop around fwrite until the request buffer is sent'
    );
}

$test = 'ModernNetwork README names RUN.cmd';
$modern_readme = @file_get_contents($root . '/ModernNetwork/README');
sc_test_assert_true($test, is_string($modern_readme),
                    'ModernNetwork/README should be readable');
if (is_string($modern_readme)) {
    sc_test_assert_true(
        $test,
        strpos($modern_readme, 'RUN.cmd') !== false
        && strpos($modern_readme, 'RUN.bat') === false,
        'ModernNetwork README should match the launcher filename'
    );
}

if (!empty($SC_TEST_FAILURES)) {
    echo "FAIL\n";
    foreach ($SC_TEST_FAILURES as $failure) {
        echo "- " . $failure . "\n";
    }
    exit(1);
}

echo "PASS\n";
exit(0);
