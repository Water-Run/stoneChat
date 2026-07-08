<?php
/* tests/smoke/test_history_crud.php
 *
 * End-to-end CRUD on a fresh chat:
 *   - create -> get id
 *   - list -> new chat present
 *   - save user + assistant messages
 *   - set_system prompt
 *   - rename
 *   - load -> verify messages + system + name
 *   - delete -> on-disk dir gone */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib.php';

echo "test_history_crud\n";
sc_test_login();
sc_test_assert_status(200, 'login');

/* 1. Create a fresh chat. */
sc_test_http('POST', '/Server/api/history.php',
    json_encode(array(
        'action' => 'new',
        'provider_id' => 'mock',
        'model' => 'mock-gpt',
    )));
sc_test_assert_status(200, 'history.new: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'history.new: ok=true');
$CHAT_ID = sc_test_extract_json('id');
if (!is_string($CHAT_ID) || $CHAT_ID === '') {
    echo "  [FAIL] could not extract chat id\n";
    echo "  last body: " . $GLOBALS['SC_LAST_BODY'] . "\n";
    exit(1);
}
echo "  [ OK ] created chat id: $CHAT_ID\n";
$GLOBALS['SC_TEST_PASS']++;

/* 2. List and verify the new chat is present. */
sc_test_http('GET', '/Server/api/history.php');
sc_test_assert_status(200, 'history.list: HTTP 200');
$obj = json_decode($GLOBALS['SC_LAST_BODY'], true);
$present = 'no';
if (is_array($obj) && isset($obj['conversations'])) {
    foreach ($obj['conversations'] as $c) {
        if (isset($c['id']) && $c['id'] === $CHAT_ID) {
            $present = 'yes';
            break;
        }
    }
}
sc_test_assert_eq('yes', $present, 'history.list: new chat present');

/* 3. Save two messages. */
sc_test_http('POST', '/Server/api/history.php',
    json_encode(array('action' => 'save', 'id' => $CHAT_ID,
        'role' => 'user', 'text' => 'hello')));
sc_test_assert_status(200, 'history.save user: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'history.save user: ok=true');

sc_test_http('POST', '/Server/api/history.php',
    json_encode(array('action' => 'save', 'id' => $CHAT_ID,
        'role' => 'assistant', 'text' => 'world')));
sc_test_assert_status(200, 'history.save assistant: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'history.save assistant: ok=true');

/* 4. set_system. */
sc_test_http('POST', '/Server/api/history.php',
    json_encode(array('action' => 'set_system', 'id' => $CHAT_ID,
        'text' => 'You are a test.')));
sc_test_assert_status(200, 'history.set_system: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'history.set_system: ok=true');

/* 5. rename. */
sc_test_http('POST', '/Server/api/history.php',
    json_encode(array('action' => 'rename', 'id' => $CHAT_ID,
        'title' => 'Smoke Test')));
sc_test_assert_status(200, 'history.rename: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'history.rename: ok=true');

/* 6. load back. */
sc_test_http('GET', '/Server/api/history.php?id=' . urlencode($CHAT_ID));
sc_test_assert_status(200, 'history.get: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'history.get: ok=true');
$obj = json_decode($GLOBALS['SC_LAST_BODY'], true);
sc_test_assert_eq('2', count($obj['messages']),
    'history.get: 2 messages');
sc_test_assert_eq('You are a test.', $obj['system'],
    'history.get: system prompt persisted');
sc_test_assert_eq('Smoke Test', $obj['meta']['name'],
    'history.get: meta.name = "Smoke Test"');

/* 7. delete. */
sc_test_http('DELETE', '/Server/api/history.php?id=' . urlencode($CHAT_ID));
sc_test_assert_status(200, 'history.delete: HTTP 200');
sc_test_assert_json_field('ok', 'true', 'history.delete: ok=true');

/* 8. The directory should be gone from disk. */
$dir = $GLOBALS['SC_PROJECT_DIR']
    . DIRECTORY_SEPARATOR . 'HISTORY' . DIRECTORY_SEPARATOR . $CHAT_ID;
usleep(200000);
if (is_dir($dir)) {
    echo "  [FAIL] history chat dir still on disk after delete: $dir\n";
    $GLOBALS['SC_TEST_FAIL']++;
} else {
    echo "  [ OK ] history chat dir removed\n";
    $GLOBALS['SC_TEST_PASS']++;
}

if ($GLOBALS['SC_TEST_FAIL'] > 0) {
    echo "  last body: " . $GLOBALS['SC_LAST_BODY'] . "\n";
    exit(1);
}
exit(0);
