<?php
/* Verify non-streaming raw HTTP responses decode chunked bodies.
 * PHP 5.2-compatible test harness. */

$failures = array();
$root = dirname(__FILE__) . '/..';
require_once $root . '/ModernNetwork/proxy.php';

function sc_test_free_port() {
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        return 0;
    }
    $name = @stream_socket_get_name($sock, false);
    @fclose($sock);
    $colon = strrpos($name, ':');
    if ($colon === false) {
        return 0;
    }
    return (int)substr($name, $colon + 1);
}

$port = sc_test_free_port();
if ($port <= 0) {
    echo "FAIL\n- could not allocate a local test port\n";
    exit(1);
}

$server_file = tempnam(sys_get_temp_dir(), 'scsrv');
$ready_file = tempnam(sys_get_temp_dir(), 'scready');
@unlink($ready_file);

$server_code = '<?php' . "\n"
    . '$port = (int)$argv[1];' . "\n"
    . '$ready = $argv[2];' . "\n"
    . '$errno = 0; $errstr = "";' . "\n"
    . '$server = @stream_socket_server("tcp://127.0.0.1:" . $port, $errno, $errstr);' . "\n"
    . 'if ($server === false) { exit(2); }' . "\n"
    . '@file_put_contents($ready, "1");' . "\n"
    . '$conn = @stream_socket_accept($server, 10);' . "\n"
    . 'if ($conn === false) { @fclose($server); exit(3); }' . "\n"
    . '@stream_set_timeout($conn, 5);' . "\n"
    . '$buf = "";' . "\n"
    . 'while (strpos($buf, "\r\n\r\n") === false && !@feof($conn)) {' . "\n"
    . '    $part = @fread($conn, 1024);' . "\n"
    . '    if ($part === false || $part === "") { break; }' . "\n"
    . '    $buf .= $part;' . "\n"
    . '}' . "\n"
    . '$resp = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n";' . "\n"
    . '$resp .= "8\r\n{\"ok\":1}\r\n0\r\n\r\n";' . "\n"
    . '@fwrite($conn, $resp);' . "\n"
    . '@fclose($conn);' . "\n"
    . '@fclose($server);' . "\n"
    . 'exit(0);' . "\n";

@file_put_contents($server_file, $server_code);

$php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($server_file)
     . ' ' . (int)$port . ' ' . escapeshellarg($ready_file);
$descriptors = array(
    0 => array('pipe', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
);
$proc = @proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    @unlink($server_file);
    echo "FAIL\n- could not start local chunked server\n";
    exit(1);
}
if (isset($pipes[0])) {
    @fclose($pipes[0]);
}

$ready = false;
for ($i = 0; $i < 50; $i++) {
    if (is_file($ready_file)) {
        $ready = true;
        break;
    }
    usleep(100000);
}

if (!$ready) {
    $failures[] = 'local chunked server did not become ready';
} else {
    $resp = sc_http_send_raw(
        $port,
        'POST',
        '127.0.0.1',
        '/chunked',
        array('Content-Type: application/json'),
        '{}',
        5,
        null,
        '127.0.0.1'
    );
    if (!is_array($resp) || isset($resp['error'])) {
        $failures[] = 'expected successful raw response';
    } else {
        if ($resp['status'] !== 200) {
            $failures[] = 'expected HTTP 200, got ' . $resp['status'];
        }
        if ($resp['body'] !== '{"ok":1}') {
            $failures[] = 'expected decoded body {"ok":1}, got '
                        . var_export($resp['body'], true);
        }
    }
}

if (isset($pipes[1])) {
    @fclose($pipes[1]);
}
if (isset($pipes[2])) {
    @fclose($pipes[2]);
}
@proc_close($proc);
@unlink($server_file);
@unlink($ready_file);

if (!empty($failures)) {
    echo "FAIL\n";
    foreach ($failures as $failure) {
        echo "- " . $failure . "\n";
    }
    exit(1);
}

echo "PASS\n";
exit(0);
