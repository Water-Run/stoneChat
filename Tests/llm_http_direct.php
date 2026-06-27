<?php
/* Verify that plain HTTP providers on LAN are sent directly, not via stunnel.
 * PHP 5.2-compatible test harness. */

$SC_TEST_HTTP_SENDS = array();
$SC_TEST_TUNNEL_CALLS = 0;

function sc_load_modern_config($ini_path) {
    return array(
        'stunnel'    => 'C:\\stunnel\\bin\\stunnel.exe',
        'ca_cert'    => 'C:\\stoneChat\\cacert.pem',
        'proxy_port' => 8443,
    );
}

function sc_ensure_tunnel($target, $cfg, $modern_dir) {
    global $SC_TEST_TUNNEL_CALLS;
    $SC_TEST_TUNNEL_CALLS++;
    return true;
}

function sc_http_send_raw($port, $method, $host, $path,
                          $headers, $body, $timeout,
                          $stream_callback = null) {
    global $SC_TEST_HTTP_SENDS;
    $args = func_get_args();
    $connect_host = isset($args[8]) ? $args[8] : '';
    $SC_TEST_HTTP_SENDS[] = array(
        'port' => $port,
        'method' => $method,
        'host' => $host,
        'path' => $path,
        'timeout' => $timeout,
        'connect_host' => $connect_host,
    );
    return array('status' => 200, 'body' => '{"ok":true}');
}

require_once dirname(__FILE__) . '/../Server/llm.php';

$provider = array(
    'type'     => 'openai',
    'api_key'  => 'local-key',
    'model'    => 'qwen2.5:14b',
    'api_base' => 'http://192.168.5.19:11434/v1',
    'timeout'  => 7,
);

$resp = sc_llm_send_via_tunnel(
    $provider,
    'POST',
    '/chat/completions',
    array('Content-Type: application/json'),
    '{}',
    null
);

$failures = array();
if ($SC_TEST_TUNNEL_CALLS !== 0) {
    $failures[] = 'expected no stunnel calls, got '
                . $SC_TEST_TUNNEL_CALLS;
}
if (count($SC_TEST_HTTP_SENDS) !== 1) {
    $failures[] = 'expected exactly one HTTP send, got '
                . count($SC_TEST_HTTP_SENDS);
} else {
    $send = $SC_TEST_HTTP_SENDS[0];
    if ($send['port'] !== 11434) {
        $failures[] = 'expected direct port 11434, got '
                    . $send['port'];
    }
    if ($send['host'] !== '192.168.5.19') {
        $failures[] = 'expected host 192.168.5.19, got '
                    . $send['host'];
    }
    if ($send['connect_host'] !== '192.168.5.19') {
        $failures[] = 'expected connect host 192.168.5.19, got '
                    . $send['connect_host'];
    }
    if ($send['timeout'] !== 7) {
        $failures[] = 'expected provider timeout 7, got '
                    . $send['timeout'];
    }
    if ($send['path'] !== '/v1/chat/completions') {
        $failures[] = 'expected /v1/chat/completions, got '
                    . $send['path'];
    }
}
if (!is_array($resp) || isset($resp['error'])) {
    $failures[] = 'expected successful response envelope';
}

$SC_TEST_HTTP_SENDS = array();
$mock_provider = array(
    'type'     => 'openai',
    'api_key'  => 'mock-key',
    'model'    => 'mock-gpt',
    'api_base' => 'http://localhost:9998/Server/api/mock_llm.php',
    'timeout'  => 11,
);
$mock_resp = sc_llm_send_via_tunnel(
    $mock_provider,
    'POST',
    '/chat/completions',
    array('Content-Type: application/json'),
    '{}',
    null
);
if (count($SC_TEST_HTTP_SENDS) !== 1) {
    $failures[] = 'expected one mock HTTP send, got '
                . count($SC_TEST_HTTP_SENDS);
} else {
    $send = $SC_TEST_HTTP_SENDS[0];
    if ($send['path'] !== '/Server/api/mock_llm.php') {
        $failures[] = 'expected mock PHP endpoint path, got '
                    . $send['path'];
    }
    if ($send['timeout'] !== 11) {
        $failures[] = 'expected mock provider timeout 11, got '
                    . $send['timeout'];
    }
}
if (!is_array($mock_resp) || isset($mock_resp['error'])) {
    $failures[] = 'expected successful mock response envelope';
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
