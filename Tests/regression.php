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

$test = 'provider loader preserves optional provider fields';
$providers = sc_load_providers($root . '/CONF.ini');
$mock_provider = null;
if (is_array($providers)) {
    foreach ($providers as $row) {
        if (is_array($row) && isset($row['id'])
            && $row['id'] === 'mock') {
            $mock_provider = $row;
            break;
        }
    }
}
sc_test_assert_true($test, is_array($mock_provider),
                    'mock provider should be present');
if (is_array($mock_provider)) {
    sc_test_assert_true(
        $test,
        isset($mock_provider['stream'])
        && (string)$mock_provider['stream'] === 'true',
        'mock provider stream=true should be preserved'
    );
}

$test = 'config validation separates fatal startup errors';
$sample_errors = array(
    'auth_password_is_placeholder',
    'paths_stunnel_missing',
    'Provider 1_api_key_is_placeholder',
    'Provider 2_unsupported_type',
);
$fatal_errors = sc_config_fatal_errors($sample_errors);
sc_test_assert_equal($test, array('auth_password_is_placeholder'),
                     $fatal_errors,
                     'only auth/server/parse errors should block startup validation');

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

$test = 'provider loader preserves explicit stream=false from INI';
$tmp_ini = tempnam(sys_get_temp_dir(), 'scini');
file_put_contents(
    $tmp_ini,
    "[llm]\nstream = true\n"
    . "[Provider 1]\n"
    . "id = raw-false\n"
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
                    'temporary provider should load');
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

$test = 'installer entry point is INSTALL.cmd';
sc_test_assert_true($test, is_file($root . '/INSTALL.cmd'),
                    'INSTALL.cmd should exist at project root');
sc_test_assert_true($test, !is_file($root . '/INSTALL.bat'),
                    'INSTALL.bat should be removed after rename');

$test = 'repository text references INSTALL.cmd';
$check_files = array(
    'README',
    'RUN.bat',
    'INSTALL.cmd',
    'Pages/js/app.js',
    'Server/install.php',
);
foreach ($check_files as $check_file) {
    $text = @file_get_contents($root . '/' . $check_file);
    sc_test_assert_true($test, is_string($text),
                        $check_file . ' should be readable');
    if (is_string($text)) {
        sc_test_assert_true(
            $test,
            strpos($text, 'INSTALL.bat') === false,
            $check_file . ' should not mention INSTALL.bat'
        );
    }
}

$test = 'launcher and installer include dependency download hints';
$php_url = 'https://windows.php.net/downloads/releases/archives/';
$stunnel_url = 'https://www.stunnel.org/downloads.html';
$hint_files = array('RUN.bat', 'INSTALL.cmd');
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

$test = 'launcher and installer require PHP built-in server runtime';
$run_text = @file_get_contents($root . '/RUN.bat');
$installer_text = @file_get_contents($root . '/INSTALL.cmd');
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, "version_compare(PHP_VERSION, '5.4.0'") !== false,
        'RUN.bat should require PHP 5.4+ because it uses php -S'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'PHP 5.2') === false,
        'RUN.bat should not advertise PHP 5.2 as runnable with php -S'
    );
}
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, "version_compare(PHP_VERSION, '5.4.0'")
        !== false,
        'INSTALL.cmd should require PHP 5.4+ because RUN.bat uses php -S'
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

$test = 'installer treats missing stunnel as preflight failure';
$installer_text = @file_get_contents($root . '/INSTALL.cmd');
sc_test_assert_true($test, is_string($installer_text),
                    'INSTALL.cmd should be readable');
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, '[FAIL] stunnel.exe not found') !== false,
        'installer should mark missing stunnel as a preflight failure'
    );
    $stunnel_fail_pos = strpos($installer_text,
                               '[FAIL] stunnel.exe not found');
    $stunnel_url_pos = strpos($installer_text,
                              'https://www.stunnel.org/downloads.html',
                              $stunnel_fail_pos);
    $stunnel_err_pos = strpos($installer_text,
                              'set /a "ERR_COUNT+=1"',
                              $stunnel_fail_pos);
    sc_test_assert_true(
        $test,
        $stunnel_fail_pos !== false && $stunnel_url_pos !== false
        && $stunnel_err_pos !== false && $stunnel_url_pos < $stunnel_err_pos,
        'installer should show stunnel download URL before incrementing error count'
    );
}

$test = 'launcher reads server port without confusing proxy port';
$run_text = @file_get_contents($root . '/RUN.bat');
sc_test_assert_true($test, is_string($run_text),
                    'RUN.bat should be readable');
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'sc_load_config') !== false
        && strpos($run_text, "['server']['port']") !== false,
        'RUN.bat should read [server] port through the PHP config loader'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'if /i "!_k!"=="port"') === false,
        'RUN.bat should not scan every INI port key'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'sc_config_fatal_errors') !== false,
        'RUN.bat should only fail CONF validation on fatal config errors'
    );
}

$test = 'installer reads server port without hardcoded 9999';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'set "SC_PORT=9999"') !== false
        && strpos($installer_text, "['server']['port']") !== false,
        'INSTALL.cmd should read [server] port through the PHP config loader'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'Port 9999 availability') === false,
        'INSTALL.cmd should not label the port check as always 9999'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'Web URL      : http://localhost:9999/') === false,
        'INSTALL.cmd should not print a hardcoded 9999 URL'
    );
}

$test = 'batch scripts guard Windows build before numeric compare';
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'findstr /R "^[0-9][0-9]*$"') !== false,
        'RUN.bat should verify WIN_BUILD is numeric before GEQ'
    );
}
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'findstr /R "^[0-9][0-9]*$"') !== false,
        'INSTALL.cmd should verify WIN_BUILD is numeric before GEQ'
    );
}

$test = 'installer warns instead of failing when disk space is unknown';
sc_test_assert_true($test, is_string($installer_text),
                    'INSTALL.cmd should be readable');
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'Could not read free disk space') !== false,
        'installer should have an unknown-disk-space warning path'
    );
}

$test = 'installer disk check avoids CMD integer overflow';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'if %FREEBYTES% LSS 104857600') === false,
        'installer should not compare large byte counts with IF numeric mode'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'FREEMB=%FREEBYTES% / 1048576') === false,
        'installer should not divide large byte counts with set /a'
    );
}

$test = 'installer preserves existing CONF.ini';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'Keeping existing CONF.ini') !== false,
        'installer should tell the user when existing config is kept'
    );
    $guard_pos = strpos(
        $installer_text,
        'if exist "%INSTALL_PATH%\\CONF.ini"'
    );
    $copy_pos = strpos(
        $installer_text,
        'copy /Y "%~dp0CONF.ini" "%INSTALL_PATH%\\"'
    );
    sc_test_assert_true(
        $test,
        $guard_pos !== false && $copy_pos !== false
        && $guard_pos < $copy_pos,
        'installer should guard CONF.ini copy with an existence check'
    );
}

$test = 'installer removes copied ModernNetwork runtime state';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text,
               'del "%INSTALL_PATH%\\ModernNetwork\\stunnel.conf"')
        !== false,
        'INSTALL.cmd should remove copied stunnel.conf runtime file'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text,
               'del "%INSTALL_PATH%\\ModernNetwork\\stunnel.pid"')
        !== false,
        'INSTALL.cmd should remove copied stunnel.pid runtime file'
    );
}

$test = 'installer creates shortcuts through Windows Script Host';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'VBS_FILE') !== false,
        'installer should materialize a VBScript shortcut helper'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'cscript //nologo "%VBS_FILE%"') !== false,
        'installer should run the shortcut helper with cscript'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'PowerShell not found. Shortcuts not created') === false,
        'installer should not make shortcut creation depend on PowerShell'
    );
}

$test = 'installer shortcut helper keeps install path out of echo expansion';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text,
               'echo installPath = ws.ExpandEnvironmentStrings("%%INSTALL_PATH%%")')
        !== false,
        'shortcut VBScript should read INSTALL_PATH from the environment'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'echo runBat = "%INSTALL_PATH%\\RUN.bat"')
        === false,
        'shortcut VBScript generation should not expand INSTALL_PATH in CMD'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'echo workDir = "%INSTALL_PATH%"') === false,
        'shortcut VBScript generation should not echo expanded workDir'
    );
}

$test = 'batch scripts reject exclamation-mark paths before delayed expansion';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'if not "%~1"==""') !== false
        && strpos($installer_text, 'set "INSTALL_PATH=%~1"') !== false,
        'INSTALL.cmd should accept install path as first argument'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'setlocal DisableDelayedExpansion')
        !== false,
        'INSTALL.cmd should start with delayed expansion disabled'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'setlocal EnableDelayedExpansion') !== false,
        'INSTALL.cmd should enable delayed expansion only after path checks'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'Current stoneChat path contains an exclamation mark') !== false
        && strpos($installer_text, 'Install path contains an exclamation mark') !== false,
        'INSTALL.cmd should reject ! in source and target paths'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, ':!=') === false,
        'INSTALL.cmd should not use CMD substring replacement to detect !'
    );
}
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'setlocal DisableDelayedExpansion') !== false,
        'RUN.bat should start with delayed expansion disabled'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'setlocal EnableDelayedExpansion') !== false,
        'RUN.bat should enable delayed expansion only after path checks'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'Current stoneChat path contains an exclamation mark') !== false,
        'RUN.bat should reject ! in its runtime path'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, ':!=') === false,
        'RUN.bat should not use CMD substring replacement to detect !'
    );
}

$test = 'batch scripts quote and validate stunnel path before use';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'Stunnel path contains an exclamation mark')
        !== false,
        'INSTALL.cmd should reject ! in stunnel path'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'if exist "!STUNNEL_PATH!"') !== false,
        'INSTALL.cmd should use delayed quoted stunnel path in existence check'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, '$c=sc_load_config(\'CONF.ini\');echo isset($c[\'paths\'][\'stunnel\'])')
        !== false,
        'INSTALL.cmd should read stunnel path through the PHP config loader when PHP is available'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'if exist "%STUNNEL_PATH%"') === false,
        'INSTALL.cmd should not use percent-expanded stunnel path'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, '[ OK ] stunnel found: "!STUNNEL_PATH!"')
        !== false
        && strpos($installer_text, '[FAIL] stunnel.exe not found at: "!STUNNEL_PATH!"')
        !== false,
        'INSTALL.cmd should quote stunnel path in output lines'
    );
}
if (is_string($run_text)) {
    sc_test_assert_true(
        $test,
        strpos($run_text, 'Stunnel path contains an exclamation mark')
        !== false,
        'RUN.bat should reject ! in stunnel path'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, 'if exist "!STUNNEL_PATH!"') !== false,
        'RUN.bat should use delayed quoted stunnel path in existence check'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, '$c=sc_load_config(\'CONF.ini\');echo isset($c[\'paths\'][\'stunnel\'])')
        !== false,
        'RUN.bat should read stunnel path through the PHP config loader when PHP is available'
    );
    sc_test_assert_true(
        $test,
        strpos($run_text, '[ OK ] stunnel found: "!STUNNEL_PATH!"')
        !== false
        && strpos($run_text, '[FAIL] stunnel.exe not found at: "!STUNNEL_PATH!"')
        !== false,
        'RUN.bat should quote stunnel path in output lines'
    );
}

$test = 'installer backend init does not validate placeholder template';
if (is_string($installer_text)) {
    sc_test_assert_true(
        $test,
        strpos($installer_text,
               'Server\\install.php" --init-history --init-langs --init-login-log')
        !== false,
        'INSTALL.cmd should run install.php with non-validating init flags'
    );
    sc_test_assert_true(
        $test,
        strpos($installer_text, 'Server\\install.php"') !== false
        && strpos($installer_text, 'php "%INSTALL_PATH%\\Server\\install.php"'
                  . "\n") === false,
        'INSTALL.cmd should not run install.php default --all during install'
    );
}
$install_php = @file_get_contents($root . '/Server/install.php');
sc_test_assert_true($test, is_string($install_php),
                    'Server/install.php should be readable');
if (is_string($install_php)) {
    sc_test_assert_true(
        $test,
        strpos($install_php, "'--init-login-log'") !== false,
        'install.php should expose a login-log-only init flag'
    );
}

$test = 'install.php generated config stub is parseable';
if (is_string($install_php)) {
    sc_test_assert_true(
        $test,
        strpos($install_php, 'label = OpenAI (ChatGPT)') === false,
        'generated CONF.ini stub should not contain unquoted parenthesized label'
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
        strpos($boot_check, 'https://www.stunnel.org/downloads.html') !== false,
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
