<?php
/* Verify connection error names for plain HTTP providers.
 * LAN providers should report connection_failed; localhost mock keeps
 * mock_unreachable so the UI can show its setup hint. PHP 5.2 style. */

function sc_load_modern_config($ini_path) {
    return array(
        'stunnel'    => 'C:\\stunnel\\bin\\stunnel.exe',
        'ca_cert'    => 'C:\\stoneChat\\cacert.pem',
        'proxy_port' => 8443,
    );
}

function sc_ensure_tunnel($target, $cfg, $modern_dir) {
    return true;
}

function sc_http_send_raw($port, $method, $host, $path,
                          $headers, $body, $timeout,
                          $stream_callback = null) {
    return array('error' => 'connection_failed');
}

require_once dirname(__FILE__) . '/../Server/llm.php';

$failures = array();

$lan = array(
    'type'     => 'openai',
    'api_key'  => 'local-key',
    'model'    => 'qwen2.5:14b',
    'api_base' => 'http://192.168.5.19:11434/v1',
);
$resp = sc_llm_send_via_tunnel(
    $lan, 'POST', '/chat/completions',
    array('Content-Type: application/json'), '{}', null
);
if (!is_array($resp) || !isset($resp['error'])
    || $resp['error'] !== 'connection_failed') {
    $got = is_array($resp) && isset($resp['error'])
           ? $resp['error'] : 'no_error';
    $failures[] = 'LAN HTTP failure should be connection_failed, got '
                . $got;
}

$mock = array(
    'type'     => 'openai',
    'api_key'  => 'mock-key',
    'model'    => 'mock',
    'api_base' => 'http://localhost:9998/v1',
);
$resp = sc_llm_send_via_tunnel(
    $mock, 'POST', '/chat/completions',
    array('Content-Type: application/json'), '{}', null
);
if (!is_array($resp) || !isset($resp['error'])
    || $resp['error'] !== 'mock_unreachable') {
    $got = is_array($resp) && isset($resp['error'])
           ? $resp['error'] : 'no_error';
    $failures[] = 'localhost mock failure should be mock_unreachable, got '
                . $got;
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
