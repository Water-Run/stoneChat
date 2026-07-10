<?php
/*
 * Minimal PHP 5.2-compatible HTTP readiness probe for the XP launcher.
 */

if ($argc < 2 || $argv[1] === '') {
    exit(1);
}

$method = $argc > 2 ? strtoupper($argv[2]) : 'GET';
$body_data = '';
$headers = '';
$expect_mock = $argc > 3 && $argv[3] === 'mock';

if ($expect_mock) {
    if ($method !== 'POST') {
        exit(1);
    }
    $body_data = json_encode(array(
        'model' => 'MockLocal',
        'messages' => array(
            array('role' => 'user', 'content' => 'readiness')
        ),
        'stream' => false
    ));
    $headers = "Content-Type: application/json\r\n"
             . 'Content-Length: ' . strlen($body_data);
}

$context = stream_context_create(array(
    'http' => array(
        'method' => $method,
        'header' => $headers,
        'content' => $body_data,
        'timeout' => 5
    )
));
$body = @file_get_contents($argv[1], false, $context);

if ($body === false || $body === '') {
    exit(1);
}

if (!isset($http_response_header[0])
    || !preg_match('/^HTTP\/1\.[01] 200/', $http_response_header[0])) {
    exit(1);
}

if ($expect_mock) {
    $response = json_decode($body, true);
    if (!is_array($response)
        || !isset($response['choices'][0]['message']['content'])
        || $response['choices'][0]['message']['content'] === '') {
        exit(1);
    }
}

exit(0);
