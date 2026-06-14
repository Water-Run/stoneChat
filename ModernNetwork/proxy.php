<?php
/**
 * stoneChat ModernNetwork -- HTTPS connectivity layer for PHP 5.2 on Windows XP.
 *
 * Uses stunnel as a local TLS tunnel to overcome PHP 5.2's lack of TLS 1.2 support.
 *
 * Usage:
 *   $result = sc_http_request('/v1/chat/completions', $json_body, $headers);
 *   // $result is array('status' => 200, 'headers' => array(), 'body' => '...')
 *   // or array('error' => 'reason') on failure.
 *
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_load_modern_config')) {
    /**
     * Load CONF.ini and return a flat array of ModernNetwork settings.
     *
     * Required keys (with defaults if missing):
     *   api_base   -- e.g. "https://api.openai.com/v1"
     *   stunnel    -- absolute path to stunnel.exe
     *   ca_cert    -- absolute path to CA bundle (cacert.pem)
     *   proxy_port -- local tunnel port (default 8443)
     *
     * @param string $ini_path Absolute or relative path to CONF.ini.
     * @return array Associative array of settings. Empty on failure.
     */
    function sc_load_modern_config($ini_path) {
        if (!function_exists('sc_load_config')) {
            require_once dirname(__FILE__) . '/../Server/config.php';
        }
        $raw = sc_load_config($ini_path);
        if (empty($raw)) {
            return array();
        }
        $api_base = isset($raw['llm']['api_base']) ? $raw['llm']['api_base'] : '';
        $stunnel  = isset($raw['paths']['stunnel']) ? $raw['paths']['stunnel'] : '';
        $ca_cert  = isset($raw['paths']['ca_cert']) ? $raw['paths']['ca_cert'] : '';
        $port     = isset($raw['proxy']['port']) ? (int)$raw['proxy']['port'] : 8443;
        return array(
            'api_base'   => $api_base,
            'stunnel'    => $stunnel,
            'ca_cert'    => $ca_cert,
            'proxy_port' => $port,
        );
    }
}

if (!function_exists('sc_parse_url_target')) {
    /**
     * Extract host and port from an api_base URL.
     *
     * Only supports https URLs (port defaults to 443).
     *
     * @param string $api_base URL like "https://api.openai.com/v1".
     * @return array array('host' => string, 'port' => int), or empty array on failure.
     */
    function sc_parse_url_target($api_base) {
        if (!is_string($api_base) || $api_base === '') {
            return array();
        }
        $parts = @parse_url($api_base);
        if (!is_array($parts) || empty($parts['host'])) {
            return array();
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        return array('host' => $parts['host'], 'port' => $port);
    }
}

if (!function_exists('sc_resolve_path')) {
    /**
     * Resolve a relative path against the project root (where CONF.ini lives).
     *
     * @param string $path Path string (absolute or relative).
     * @param string $base_dir Directory of CONF.ini.
     * @return string Absolute path, or the original if already absolute.
     */
    function sc_resolve_path($path, $base_dir) {
        if (!is_string($path) || $path === '') {
            return '';
        }
        // Windows: drive letter like "C:\" -- treat as absolute.
        if (strlen($path) >= 2 && $path[1] === ':') {
            return str_replace('/', '\\', $path);
        }
        // Unix-style absolute.
        if ($path[0] === '/' || $path[0] === '\\') {
            return $path;
        }
        $base = rtrim($base_dir, '/\\');
        $sep  = (strpos($base, '\\') !== false) ? '\\' : '/';
        return $base . $sep . str_replace('/', $sep, $path);
    }
}

if (!function_exists('sc_stunnel_conf_path')) {
    /**
     * Return the absolute path where the generated stunnel.conf should be written.
     *
     * @param string $modern_dir Absolute path to the ModernNetwork/ directory.
     * @return string Absolute path to stunnel.conf.
     */
    function sc_stunnel_conf_path($modern_dir) {
        return rtrim($modern_dir, '/\\') . DIRECTORY_SEPARATOR . 'stunnel.conf';
    }
}

if (!function_exists('sc_stunnel_pid_path')) {
    /**
     * Return the absolute path where stunnel writes its PID file.
     *
     * @param string $modern_dir Absolute path to the ModernNetwork/ directory.
     * @return string Absolute path to stunnel.pid.
     */
    function sc_stunnel_pid_path($modern_dir) {
        return rtrim($modern_dir, '/\\') . DIRECTORY_SEPARATOR . 'stunnel.pid';
    }
}

if (!function_exists('sc_read_stunnel_target')) {
    /**
     * Read an existing stunnel.conf and return the connect host:port it targets.
     *
     * @param string $conf_path Absolute path to stunnel.conf.
     * @return array array('host' => string, 'port' => int) on success, or array() if not found / unparseable.
     */
    function sc_read_stunnel_target($conf_path) {
        if (!is_file($conf_path) || !is_readable($conf_path)) {
            return array();
        }
        $lines = @file($conf_path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return array();
        }
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments and section headers.
            if ($line === '' || $line[0] === ';' || $line[0] === '#' || $line[0] === '[') {
                continue;
            }
            if (stripos($line, 'connect') === 0) {
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $value = trim(substr($line, $eq + 1));
                $colon = strrpos($value, ':');
                if ($colon === false) {
                    return array('host' => $value, 'port' => 443);
                }
                $host = trim(substr($value, 0, $colon));
                $port = (int)trim(substr($value, $colon + 1));
                return array('host' => $host, 'port' => $port);
            }
        }
        return array();
    }
}

if (!function_exists('sc_generate_stunnel_conf')) {
    /**
     * Write a fresh stunnel.conf for the given target.
     *
     * @param string $conf_path  Absolute path to write to.
     * @param string $pid_path   Absolute path for stunnel's pid file.
     * @param string $ca_cert    Absolute path to the CA bundle.
     * @param int    $local_port Local port to listen on (127.0.0.1:port).
     * @param string $remote_host Remote host to tunnel to.
     * @param int    $remote_port Remote port (usually 443).
     * @return bool true on success, false on failure.
     */
    function sc_generate_stunnel_conf($conf_path, $pid_path, $ca_cert, $local_port, $remote_host, $remote_port) {
        $body = "; Auto-generated by ModernNetwork/proxy.php. Do not edit.\n"
              . "client = yes\n"
              . "pid = " . $pid_path . "\n"
              . "CAfile = " . $ca_cert . "\n"
              . "verify = 2\n"
              . "\n"
              . "[api-tunnel]\n"
              . "accept = 127.0.0.1:" . (int)$local_port . "\n"
              . "connect = " . $remote_host . ":" . (int)$remote_port . "\n";
        $bytes = @file_put_contents($conf_path, $body);
        return ($bytes !== false);
    }
}

if (!function_exists('sc_pid_alive')) {
    /**
     * Check if a process with the given PID is running.
     *
     * Uses `tasklist` on Windows, `ps` on Unix.
     *
     * @param int $pid Process ID.
     * @return bool true if running.
     */
    function sc_pid_alive($pid) {
        $pid = (int)$pid;
        if ($pid <= 0) {
            return false;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = array();
            $rc = 0;
            // /FI "PID eq N" filters; /NH skips header.
            @exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $output, $rc);
            if (!is_array($output) || count($output) === 0) {
                return false;
            }
            // tasklist prints "INFO: No tasks are running..." when no match.
            $line = implode("\n", $output);
            if (strpos($line, 'No tasks') !== false) {
                return false;
            }
            return true;
        }
        // Unix: kill -0 succeeds if process exists.
        @exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $rc);
        return ($rc === 0);
    }
}

if (!function_exists('sc_stunnel_is_running')) {
    /**
     * Check if stunnel is running by reading its PID file.
     *
     * @param string $pid_path Absolute path to stunnel.pid.
     * @return int|false PID (int) if running, false otherwise.
     */
    function sc_stunnel_is_running($pid_path) {
        if (!is_file($pid_path) || !is_readable($pid_path)) {
            return false;
        }
        $pid = (int)trim(@file_get_contents($pid_path));
        if ($pid <= 0) {
            return false;
        }
        return sc_pid_alive($pid) ? $pid : false;
    }
}

if (!function_exists('sc_stunnel_stop')) {
    /**
     * Stop a running stunnel by killing its PID.
     *
     * @param string $pid_path Absolute path to stunnel.pid.
     * @return bool true if stopped (or wasn't running), false on error.
     */
    function sc_stunnel_stop($pid_path) {
        $pid = sc_stunnel_is_running($pid_path);
        if ($pid === false) {
            // Also clear stale pid file.
            if (is_file($pid_path)) {
                @unlink($pid_path);
            }
            return true;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec('taskkill /F /PID ' . $pid . ' 2>NUL', $output, $rc);
        } else {
            @exec('kill ' . $pid . ' 2>/dev/null', $output, $rc);
        }
        // Give it a moment to die.
        usleep(200000);
        if (is_file($pid_path)) {
            @unlink($pid_path);
        }
        return (sc_stunnel_is_running($pid_path) === false);
    }
}

if (!function_exists('sc_stunnel_start')) {
    /**
     * Launch stunnel with the given config file.
     *
     * @param string $stunnel_exe Absolute path to stunnel.exe.
     * @param string $conf_path   Absolute path to stunnel.conf.
     * @param string $pid_path    Absolute path where stunnel will write its pid.
     * @return bool true on success.
     */
    function sc_stunnel_start($stunnel_exe, $conf_path, $pid_path) {
        if (!is_file($stunnel_exe)) {
            return false;
        }
        if (!is_file($conf_path)) {
            return false;
        }
        // Quote paths for Windows safety (spaces in "Program Files").
        $cmd = '"' . $stunnel_exe . '" "' . $conf_path . '"';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Start detached: use "start /B" via cmd /c.
            $cmd = 'cmd /c start /B "" ' . $cmd;
            @exec($cmd);
        } else {
            @exec($cmd . ' >/dev/null 2>&1 &');
        }
        // Wait up to ~3 seconds for the PID file to appear.
        for ($i = 0; $i < 30; $i++) {
            usleep(100000);
            if (sc_stunnel_is_running($pid_path) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('sc_ensure_tunnel')) {
    /**
     * Ensure a stunnel tunnel is running and targeting the expected host.
     *
     * Generates a fresh config and restarts stunnel if the target has changed.
     *
     * @param array  $target     array('host' => string, 'port' => int)
     * @param array  $cfg        ModernNetwork config from sc_load_modern_config()
     * @param string $modern_dir Absolute path to ModernNetwork/
     * @return bool true if tunnel is up and pointing at $target.
     */
    function sc_ensure_tunnel($target, $cfg, $modern_dir) {
        $conf_path = sc_stunnel_conf_path($modern_dir);
        $pid_path  = sc_stunnel_pid_path($modern_dir);
        $ca_cert   = sc_resolve_path($cfg['ca_cert'], dirname($modern_dir));
        $current   = sc_read_stunnel_target($conf_path);
        $matches   = !empty($current)
                  && $current['host'] === $target['host']
                  && (int)$current['port'] === (int)$target['port'];
        if ($matches && sc_stunnel_is_running($pid_path) !== false) {
            return true;
        }
        // Stop any stale instance.
        sc_stunnel_stop($pid_path);
        // Generate fresh config.
        $ok = sc_generate_stunnel_conf(
            $conf_path,
            $pid_path,
            $ca_cert,
            $cfg['proxy_port'],
            $target['host'],
            $target['port']
        );
        if (!$ok) {
            return false;
        }
        return sc_stunnel_start($cfg['stunnel'], $conf_path, $pid_path);
    }
}

if (!function_exists('sc_http_send_raw')) {
    /**
     * Send a raw HTTP request over a TCP socket and read the full response.
     *
     * @param int    $port     Local port (stunnel listens here).
     * @param string $method   HTTP method (GET, POST, etc.).
     * @param string $host     Host header value.
     * @param string $path     Request path.
     * @param array  $headers  Extra headers (each a full header line, without trailing CRLF).
     * @param string $body     Request body (empty string for none).
     * @param int    $timeout  Read/write timeout in seconds.
     * @return array array('status' => int, 'headers' => array, 'body' => string) or array('error' => string)
     */
    function sc_http_send_raw($port, $method, $host, $path, $headers, $body, $timeout) {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen('127.0.0.1', (int)$port, $errno, $errstr, (int)$timeout);
        if ($fp === false) {
            return array('error' => 'connection_failed');
        }
        @stream_set_timeout($fp, (int)$timeout);
        // Build request.
        $req  = $method . ' ' . $path . " HTTP/1.1\r\n";
        $req .= 'Host: ' . $host . "\r\n";
        $req .= 'Connection: close' . "\r\n";
        $req .= 'Content-Length: ' . strlen($body) . "\r\n";
        if (is_array($headers)) {
            foreach ($headers as $h) {
                $req .= $h . "\r\n";
            }
        }
        $req .= "\r\n" . $body;
        if (@fwrite($fp, $req) === false) {
            @fclose($fp);
            return array('error' => 'write_failed');
        }
        // Read response.
        $raw = '';
        while (!@feof($fp)) {
            $chunk = @fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        @fclose($fp);
        // Parse status line.
        $sep = strpos($raw, "\r\n");
        if ($sep === false) {
            return array('error' => 'malformed_response');
        }
        $status_line = substr($raw, 0, $sep);
        if (!preg_match('#^HTTP/\S+\s+(\d+)#', $status_line, $m)) {
            return array('error' => 'malformed_response');
        }
        $status = (int)$m[1];
        // Parse headers and body.
        $rest = substr($raw, $sep + 2);
        $h_end = strpos($rest, "\r\n\r\n");
        if ($h_end === false) {
            return array('error' => 'malformed_response');
        }
        $header_block = substr($rest, 0, $h_end);
        $body_out = substr($rest, $h_end + 4);
        $hdr_lines = explode("\r\n", $header_block);
        $hdrs = array();
        foreach ($hdr_lines as $h) {
            $c = strpos($h, ':');
            if ($c !== false) {
                $k = trim(substr($h, 0, $c));
                $v = trim(substr($h, $c + 1));
                $hdrs[$k] = $v;
            }
        }
        return array('status' => $status, 'headers' => $hdrs, 'body' => $body_out);
    }
}

if (!function_exists('sc_http_request')) {
    /**
     * Send an HTTP request through the ModernNetwork tunnel.
     *
     * @param string $path    Full request path, e.g. "/v1/chat/completions".
     * @param string $body    Request body (JSON string). Use "" or null for GET.
     * @param array  $headers Extra HTTP header lines (without trailing CRLF).
     * @return array array('status' => int, 'headers' => array, 'body' => string)
     *               or array('error' => string) on failure.
     */
    function sc_http_request($path, $body, $headers) {
        // Resolve project root (where CONF.ini lives) and ModernNetwork dir.
        $ini_path = dirname(__FILE__) . '/../CONF.ini';
        $modern_dir = dirname(__FILE__);
        $cfg = sc_load_modern_config($ini_path);
        if (empty($cfg) || $cfg['api_base'] === '' || $cfg['stunnel'] === '' || $cfg['ca_cert'] === '') {
            return array('error' => 'config_error');
        }
        $target = sc_parse_url_target($cfg['api_base']);
        if (empty($target)) {
            return array('error' => 'config_error');
        }
        if (!sc_ensure_tunnel($target, $cfg, $modern_dir)) {
            return array('error' => 'stunnel_start_failed');
        }
        $body_str = ($body === null) ? '' : (string)$body;
        $hdrs = is_array($headers) ? $headers : array();
        $resp = sc_http_send_raw(
            $cfg['proxy_port'],
            $body_str === '' ? 'GET' : 'POST',
            $target['host'],
            $path,
            $hdrs,
            $body_str,
            30
        );
        if (isset($resp['error']) && $resp['error'] === 'connection_failed') {
            // Tunnel died between ensure and connect; try once more.
            if (sc_ensure_tunnel($target, $cfg, $modern_dir)) {
                $resp = sc_http_send_raw(
                    $cfg['proxy_port'],
                    $body_str === '' ? 'GET' : 'POST',
                    $target['host'],
                    $path,
                    $hdrs,
                    $body_str,
                    30
                );
            }
        }
        return $resp;
    }
}
