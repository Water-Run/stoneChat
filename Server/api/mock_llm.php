<?php
/**
 * stoneChat Mock LLM Endpoint.
 * OpenAI-compatible /chat/completions mock.
 * Supports streaming (SSE) and non-streaming modes.
 */

header('Access-Control-Allow-Origin: *');
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
