<?php
/**
 * stoneChat Mock LLM Endpoint.
 * OpenAI-compatible /chat/completions mock.
 * Supports streaming (SSE) and non-streaming modes.
 *
 * CORS: this endpoint is intended for the local stoneChat dev loop
 * (the browser hits it on 127.0.0.1 / localhost through the same PHP
 * server). Wide-open CORS was previously enabled with "*", which let
 * any external origin invoke the mock and burn CPU. The current
 * policy:
 *   - No Origin header at all (same-origin request): always allowed.
 *   - Origin matches http://localhost:<port> or
 *     http://127.0.0.1:<port>: allowed.
 *   - Any other Origin: respond 403 and refuse to serve.
 */

$sc_mock_allowed = false;
if (!isset($_SERVER['HTTP_ORIGIN']) || $_SERVER['HTTP_ORIGIN'] === '') {
    $sc_mock_allowed = true;
} else {
    $sc_origin = (string)$_SERVER['HTTP_ORIGIN'];
    if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i',
                   $sc_origin)) {
        $sc_mock_allowed = true;
    }
}

if (!$sc_mock_allowed) {
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('ok' => false, 'error' => 'forbidden_origin'));
    exit;
}

if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== '') {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$stream = isset($data['stream']) && $data['stream'];

if ($stream) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    while (ob_get_level()) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $chunks = array("Hello", "!", " This", " is", " a", " live", " streaming", " mock", " response", " from", " your", " local", " stoneChat", " server", ".");
    
    foreach ($chunks as $chunk) {
        if (function_exists('connection_aborted') && connection_aborted()) {
            break;
        }
        $event = array(
            'choices' => array(
                array(
                    'delta' => array(
                        'content' => $chunk
                    )
                )
            )
        );
        echo "data: " . json_encode($event) . "\n\n";
        usleep(100000); // 100ms delay to simulate network latency
    }
    echo "data: [DONE]\n\n";
    exit;
} else {
    header('Content-Type: application/json');
    $response = array(
        'choices' => array(
            array(
                'message' => array(
                    'role' => 'assistant',
                    'content' => 'Hello! This is a static mock response from your local stoneChat server.'
                )
            )
        )
    );
    echo json_encode($response);
    exit;
}
