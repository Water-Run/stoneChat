<?php
/* stoneChat regression tests.
 * PHP 5.2-compatible: no namespaces, closures, or short arrays. */

$SC_TEST_FAILURES = array();
$SC_TEST_STREAM_CHUNKS = array();
$SC_TEST_TRANSPORT_BODIES = array();

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

/* Stub transport before loading Server/llm.php. The production file guards
 * sc_llm_send_via_tunnel with function_exists(), so this lets the test run
 * the real provider code without opening sockets. */
function sc_llm_send_via_tunnel($provider_config, $method, $path_suffix,
                                $headers, $body, $stream_callback = null) {
    global $SC_TEST_TRANSPORT_BODIES;
    $SC_TEST_TRANSPORT_BODIES[] = $body;
    $payload = '';
    $payload .= "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n";
    $payload .= "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n";
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

function sc_test_router_probe($path) {
    $root = dirname(__FILE__) . '/..';
    $cmd = 'cd ' . escapeshellarg($root) . ' && php -r '
         . escapeshellarg(
             'function sc_strict_environment_check(){}'
             . '$_SERVER["REQUEST_URI"]=' . var_export($path, true) . ';'
             . '$_SERVER["QUERY_STRING"]="";'
             . 'ob_start();'
             . '$r=require "Pages/router.php";'
             . '$body=ob_get_clean();'
             . 'echo "return=" . ($r ? "true" : "false") . "\n";'
             . 'echo "body=" . $body . "\n";'
         );
    $out = array();
    $code = 0;
    @exec($cmd, $out, $code);
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
        'allow_config' => 'true',
        'allow_models' => '*',
    ),
    'User Guest' => array(
        'password' => 'guestpass',
        'active' => 'true',
        'allow_config' => 'false',
        'allow_models' => 'GPT55',
    ),
    'User Off' => array(
        'password' => 'offpass',
        'active' => 'false',
        'allow_config' => 'false',
        'allow_models' => '*',
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
$admin_token = sc_auth_generate_token($auth_cfg, $admin_user);
$admin_ctx = sc_auth_token_context($admin_token, $auth_cfg);
sc_test_assert_equal($test, 'Admin',
                     isset($admin_ctx['username'])
                     ? $admin_ctx['username'] : '',
                     'token should preserve username');

$test = 'user model policy allows configured model ids';
$provider_rows = array(
    array('id' => 'GPT55'),
    array('id' => 'MiniMaxM3'),
    array('id' => 'MockLocal'),
);
$guest_rows = sc_auth_filter_providers($provider_rows, $auth_cfg, 'Guest');
$admin_rows = sc_auth_filter_providers($provider_rows, $auth_cfg, 'Admin');
sc_test_assert_equal($test, 1, count($guest_rows),
                     'Guest should only see allowed models');
sc_test_assert_equal($test, 'GPT55',
                     isset($guest_rows[0]['id']) ? $guest_rows[0]['id'] : '',
                     'Guest remaining model should be GPT55');
sc_test_assert_equal($test, 3, count($admin_rows),
                     'Admin should see all models');

$test = 'config validation catches user model mistakes';
$bad_model_cfg = array(
    'server' => array('port' => '9999'),
    'User Admin' => array(
        'password' => 'admin123',
        'active' => 'maybe',
        'allow_config' => 'true',
        'allow_models' => 'MiniMaxM3,MissingModel',
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
    in_array('User Admin_allow_model_missing:MissingModel',
             $bad_model_errors, true),
    'unknown user allow_models entry should be reported'
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
        'password' => 'admin123',
        'active' => '1',
        'allow_config' => '1',
        'allow_models' => '*',
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

$test = 'sc_validate_path_resolve preserves POSIX absolute base';
$resolved = sc_validate_path_resolve(
    '../ModernNetwork/cacert.pem',
    dirname(__FILE__) . '/../Server'
);
sc_test_assert_true($test, strlen($resolved) > 0 && $resolved[0] === '/',
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
$providers = sc_load_providers($root . '/CONF.ini');
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
    'User Guest_allow_model_missing:NoSuchModel',
    'paths_stunnel_missing',
    'Model GPT55_api_key_is_placeholder',
    'Model BadModel_invalid_type',
);
$fatal_errors = sc_config_fatal_errors($sample_errors);
sc_test_assert_equal($test, array(
                         'auth_user_password_is_placeholder',
                         'User Guest_allow_model_missing:NoSuchModel',
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
        'sample config should include default Admin user'
    );
    sc_test_assert_true(
        $test,
        strpos($sample_ini, '[User Guest]') !== false
        && strpos($sample_ini, 'password = guestpass') !== false,
        'sample config should include default Guest user'
    );
    sc_test_assert_true(
        $test,
        strpos($sample_ini, '[Model MiniMaxM3]') !== false
        && strpos($sample_ini, '[Model GPT55]') !== false
        && strpos($sample_ini, 'allow_models =') !== false
        && strpos($sample_ini, 'api_base =') !== false
        && strpos($sample_ini, '[Provider ') === false
        && strpos($sample_ini, 'model_id =') === false,
        'sample config should explain direct model units'
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

$test = 'router returns explicit 404 for Server library PHP files';
$probe = sc_test_router_probe('/Server/config.php');
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle the request itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');

$test = 'router returns explicit 404 for missing Pages files';
$probe = sc_test_router_probe('/Pages/missing.htm');
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle the missing page itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');

$test = 'router does not expose CONF.ini';
$probe = sc_test_router_probe('/CONF.ini');
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle CONF.ini itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');

$test = 'router does not expose HISTORY runtime files';
$probe = sc_test_router_probe('/HISTORY/example/meta.txt');
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle HISTORY itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should emit a 404 body');

$test = 'router blocks path traversal before static fallback';
$probe = sc_test_router_probe('/Pages/../CONF.ini');
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute for Pages traversal');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle Pages traversal itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should 404 Pages traversal');
$probe = sc_test_router_probe('/Assets/../CONF.ini');
sc_test_assert_equal($test, 0, $probe['code'],
                     'router probe should execute for Assets traversal');
sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                    'router should handle Assets traversal itself');
sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                    'router should 404 Assets traversal');
$probe = sc_test_router_probe('/Server/api/../config.php');
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
    $probe = sc_test_router_probe('/ModernNetwork/' . $runtime_name);
    @unlink($runtime_path);
    sc_test_assert_equal($test, 0, $probe['code'],
                         'router probe should execute for ' . $runtime_name);
    sc_test_assert_true($test, strpos($probe['text'], 'return=true') !== false,
                        'router should handle ' . $runtime_name . ' itself');
    sc_test_assert_true($test, strpos($probe['text'], '404 Not Found') !== false,
                        'router should 404 ' . $runtime_name);
}

$test = 'modern Windows cookie is readable by modern banner';
$router_php = @file_get_contents($root . '/Pages/router.php');
sc_test_assert_true($test, is_string($router_php),
                    'Pages/router.php should be readable');
if (is_string($router_php)) {
    sc_test_assert_true(
        $test,
        strpos($router_php,
               "setcookie('sc_modern', '1', time() + 31536000, '/', '', false, false)")
        !== false,
        'sc_modern cookie should not be HttpOnly because modern-banner.js reads it'
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
    sc_test_assert_true(
        $test,
        strpos($editor_text, "\n<?xml") === false,
        'editor.php should not contain a raw XML declaration after PHP'
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
$readme_text = @file_get_contents($root . '/README');
sc_test_assert_true($test, is_string($readme_text),
                    'README should be readable');
if (is_string($readme_text)) {
    sc_test_assert_true(
        $test,
        strpos($readme_text, 'PHP 5.4') !== false,
        'README should document PHP 5.4+ runtime requirement'
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
$chat_php = @file_get_contents($root . '/Server/api/chat.php');
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
