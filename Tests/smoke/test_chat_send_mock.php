<?php
/* tests/smoke/test_chat_send_mock.php
 *
 * Drives a real /api/chat.php round-trip against the bundled
 * Server/api/mock_llm.php. Regression target: the bug that fired
 * the stream callback twice for every chunk
 * (HISTORY/20260615032356-2f62/assistant-001.txt had the mock
 * text twice in a row).
 *
 * Flow:
 *   1. start mock on 9998 (separate php -S)
 *   2. login
 *   3. create a fresh chat
 *   4. send a non-streaming message; expect exactly one mock line
 *   5. verify the persisted assistant file does NOT duplicate
 *   6. send a streaming message
 *   7. verify the streamed assistant file does NOT duplicate */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_chat_send_mock\n";

/* 1. Start the mock on 9998. The mock is the same router.php
 *    the main server uses; the test config points Provider 1
 *    (mock) at http://127.0.0.1:9998/Server/api/mock_llm.php. */
$mock_started = sc_test_start_server(9998,
    $GLOBALS['SC_PROJECT_DIR'],
    $GLOBALS['SC_PROJECT_DIR'] . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . 'router.php',
    /* mock = */ true);
if (!$mock_started) {
    echo "  [FAIL] could not start mock server\n";
    exit(1);
}
$mock_status = sc_test_wait_server(9998, 5);
if ($mock_status < 200 || $mock_status >= 400) {
    echo "  [FAIL] mock server did not come up (status $mock_status)\n";
    sc_test_stop_server();
    exit(1);
}
echo "  [ OK ] mock server alive (status $mock_status)\n";
$GLOBALS['SC_TEST_PASS']++;

/* 2. login on the main server. */
sc_test_login();
sc_test_assert_status(200, 'login');

/* 3. create a fresh chat. */
sc_test_http('POST', '/Server/api/history.php',
    json_encode(array('action' => 'new', 'provider_id' => 'mock',
        'model' => 'mock-gpt')));
sc_test_assert_status(200, 'history.new');
$CHAT_ID = sc_test_extract_json('id');
if (!is_string($CHAT_ID) || $CHAT_ID === '') {
    echo "  [FAIL] no chat id in response\n";
    sc_test_stop_server();
    exit(1);
}
echo "  [ OK ] chat id: $CHAT_ID\n";

/* 4. non-streaming send. */
$expected = 'Hello! This is a static mock response from your local stoneChat server.';
sc_test_http('POST', '/Server/api/chat.php',
    json_encode(array('action' => 'send', 'chat_id' => $CHAT_ID,
        'message' => 'ping')));
sc_test_assert_status(200, 'chat.send non-stream: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'chat.send non-stream: ok=true');
sc_test_assert_eq($expected, sc_test_extract_json('assistant'),
    'chat.send non-stream: assistant content');

/* 5. persisted assistant file does NOT duplicate. */
$assFile = $GLOBALS['SC_PROJECT_DIR']
    . DIRECTORY_SEPARATOR . 'HISTORY' . DIRECTORY_SEPARATOR
    . $CHAT_ID . DIRECTORY_SEPARATOR . 'assistant-001.txt';
if (is_file($assFile)) {
    $content = file_get_contents($assFile);
    if ($content === $expected) {
        echo "  [ OK ] persisted assistant file has the expected text exactly once\n";
        $GLOBALS['SC_TEST_PASS']++;
    } else {
        echo "  [FAIL] persisted assistant file does not match expected (duplicate?)\n";
        echo "         file:     " . var_export($content, true) . "\n";
        echo "         expected: " . var_export($expected, true) . "\n";
        $GLOBALS['SC_TEST_FAIL']++;
    }
} else {
    echo "  [FAIL] no assistant file at $assFile\n";
    $GLOBALS['SC_TEST_FAIL']++;
}

/* 6. streaming send via raw fsockopen. The frontend uses the
 *    text/event-stream Accept header to opt into the streaming
 *    handler in chat.php. We do the same here. */
$url = $GLOBALS['SC_BASE_URL'] . '/Server/api/chat.php';
$body = json_encode(array('action' => 'send', 'chat_id' => $CHAT_ID,
    'message' => 'stream please'));
$cookies = sc_test_collect_cookies();
$req  = "POST /Server/api/chat.php HTTP/1.1\r\n";
$req .= 'Host: ' . $GLOBALS['SC_HOST'] . "\r\n";
$req .= 'Content-Type: application/json' . "\r\n";
$req .= 'Accept: text/event-stream, application/json' . "\r\n";
$req .= 'Content-Length: ' . strlen($body) . "\r\n";
$req .= 'Connection: close' . "\r\n";
if (count($cookies) > 0) {
    $req .= 'Cookie: ' . implode('; ', $cookies) . "\r\n";
}
$req .= "\r\n" . $body;

$errno = 0;
$errstr = '';
$fp = @fsockopen($GLOBALS['SC_HOST'], $GLOBALS['SC_PORT'], $errno, $errstr, 10);
if ($fp === false) {
    echo "  [FAIL] could not open socket to test server: $errstr\n";
    $GLOBALS['SC_TEST_FAIL']++;
    sc_test_cleanup_history($CHAT_ID);
    sc_test_stop_server();
    exit(1);
}
@stream_set_timeout($fp, 30);
@fwrite($fp, $req);
$raw = '';
while (!@feof($fp)) {
    $chunk = @fread($fp, 8192);
    if ($chunk === false || $chunk === '') { break; }
    $raw .= $chunk;
}
@fclose($fp);

$sep = strpos($raw, "\r\n\r\n");
$streamStatus = 0;
$streamBody = $raw;
if ($sep !== false) {
    $head_block = substr($raw, 0, $sep);
    $streamBody = substr($raw, $sep + 4);
    if (preg_match('#^HTTP/\S+\s+(\d+)#', strtok($head_block, "\r\n"), $m)) {
        $streamStatus = (int)$m[1];
    }
}

if (strncmp($streamBody, 'data:', 5) === 0) {
    echo "  [ OK ] stream response is SSE (status $streamStatus)\n";
    $GLOBALS['SC_TEST_PASS']++;
} else {
    echo "  [FAIL] stream response does not look like SSE (status $streamStatus)\n";
    echo "         body: " . substr($streamBody, 0, 200) . "\n";
    $GLOBALS['SC_TEST_FAIL']++;
}

/* 7. streamed assistant file does NOT duplicate. */
usleep(200000);
$assDir = $GLOBALS['SC_PROJECT_DIR']
    . DIRECTORY_SEPARATOR . 'HISTORY' . DIRECTORY_SEPARATOR
    . $CHAT_ID;
$assFiles = array();
if (is_dir($assDir)) {
    $dh = @opendir($assDir);
    if ($dh !== false) {
        while (($n = @readdir($dh)) !== false) {
            if (preg_match('/^assistant-\d{3}\.txt$/', $n)) {
                $assFiles[] = $n;
            }
        }
        closedir($dh);
    }
    sort($assFiles, SORT_STRING);
}
if (count($assFiles) > 0) {
    $last = $assFiles[count($assFiles) - 1];
    $lastContent = file_get_contents($assDir . DIRECTORY_SEPARATOR . $last);
    $expectedStreamed = 'Hello! This is a live streaming mock response from your local stoneChat server.';
    if ($lastContent === $expectedStreamed) {
        echo "  [ OK ] streamed assistant file has the expected text exactly once\n";
        $GLOBALS['SC_TEST_PASS']++;
    } else {
        echo "  [FAIL] streamed assistant file is duplicated or wrong\n";
        echo "         file:     " . var_export($lastContent, true) . "\n";
        echo "         expected: " . var_export($expectedStreamed, true) . "\n";
        $GLOBALS['SC_TEST_FAIL']++;
    }
} else {
    echo "  [FAIL] no streamed assistant file was written\n";
    $GLOBALS['SC_TEST_FAIL']++;
}

/* 8. Cleanup: delete the chat so the project HISTORY/ stays clean. */
sc_test_cleanup_history($CHAT_ID);
sc_test_stop_server();

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    exit(1);
}
exit(0);
