<?php
/* -------------------------------------------------------------------------
 * stoneChat / ModernNetwork/proxy.php
 *
 * HTTPS connectivity layer for PHP 5.2 on Windows XP. Uses stunnel
 * as a local TLS tunnel to overcome PHP 5.2's lack of TLS 1.2 support.
 *
 * Usage:
 *   $result = sc_http_request('/v1/chat/completions', $json_body,
 *                             $headers);
 *   // $result is array('status' => 200, 'headers' => array(),
 *   //                   'body' => '...')
 *   // or array('error' => 'reason') on failure.
 *
 * Public helpers (sc_-prefixed, function_exists guarded):
 *   sc_load_modern_config($ini_path)    flat ModernNetwork settings
 *   sc_parse_url_target($api_base)      host/port from api_base
 *   sc_resolve_path($path, $base_dir)   absolute path resolution
 *   sc_stunnel_conf_path($modern_dir)   absolute path to stunnel.conf
 *   sc_stunnel_pid_path($modern_dir)    absolute path to stunnel.pid
 *   sc_read_stunnel_target($conf_path)  parse existing conf target
 *   sc_generate_stunnel_conf(...)       write a fresh stunnel.conf
 *   sc_pid_alive($pid)                  is the stunnel PID still running?
 *   sc_stunnel_is_running($pid_path)    is stunnel up? (PID or false)
 *   sc_stunnel_stop($pid_path)          stop a running stunnel
 *   sc_stunnel_start($exe, $conf, $pid) launch stunnel with conf
 *   sc_ensure_tunnel($target, $cfg, $d) ensure tunnel targets $target
 *   sc_http_send_raw(...)               raw socket-level HTTP send
 *   sc_http_request($path, $body, $h)   send through the tunnel
 *
 * Class:
 *   SC_ChunkedParser                    real-time chunked response parser
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_load_modern_config($ini_path)
 *   Load CONF.ini and return a flat array of ModernNetwork settings.
 *   Required keys (with defaults if missing):
 *     api_base   -- e.g. "https://api.openai.com/v1"
 *     stunnel    -- absolute path to stunnel.exe
 *     ca_cert    -- absolute path to CA bundle (cacert.pem)
 *     proxy_port -- local tunnel port (default 8443) */
if (!function_exists('sc_load_modern_config')) {
    function sc_load_modern_config($ini_path) {
        if (!function_exists('sc_load_config')) {
            require_once dirname(__FILE__) . '/../Server/config.php';
        }
        $raw = sc_load_config($ini_path);
        if (empty($raw)) {
            return array();
        }
        $api_base = isset($raw['llm']['api_base'])
                    ? $raw['llm']['api_base'] : '';
        $stunnel  = isset($raw['paths']['stunnel'])
                    ? $raw['paths']['stunnel'] : '';
        $ca_cert  = isset($raw['paths']['ca_cert'])
                    ? $raw['paths']['ca_cert'] : '';
        $port     = isset($raw['proxy']['port'])
                    ? (int)$raw['proxy']['port'] : 8443;
        return array(
            'api_base'   => $api_base,
            'stunnel'    => $stunnel,
            'ca_cert'    => $ca_cert,
            'proxy_port' => $port,
        );
    }
}

/* sc_parse_url_target($api_base)
 *   Extract host and port from an api_base URL. Only https URLs
 *   are supported (port defaults to 443). */
if (!function_exists('sc_parse_url_target')) {
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

/* sc_resolve_path($path, $base_dir)
 *   Resolve a relative path against a base directory. */
if (!function_exists('sc_resolve_path')) {
    function sc_resolve_path($path, $base_dir) {
        if (function_exists('sc_validate_path_resolve')) {
            return sc_validate_path_resolve($path, $base_dir);
        }
        if (!is_string($path) || $path === '') {
            return '';
        }
        /* Windows: drive letter like "C:\" -- treat as absolute. */
        if (strlen($path) >= 2 && $path[1] === ':') {
            return str_replace('/', '\\', $path);
        }
        /* Unix-style absolute. */
        if ($path[0] === '/' || $path[0] === '\\') {
            return $path;
        }
        $base = rtrim($base_dir, '/\\');
        $sep  = (strpos($base, '\\') !== false) ? '\\' : '/';
        return $base . $sep . str_replace('/', $sep, $path);
    }
}

/* sc_stunnel_conf_path($modern_dir)
 *   Absolute path where the generated stunnel.conf is written. */
if (!function_exists('sc_stunnel_conf_path')) {
    function sc_stunnel_conf_path($modern_dir) {
        $real = realpath($modern_dir);
        if (!$real) $real = $modern_dir;
        return rtrim($real, '/\\') . DIRECTORY_SEPARATOR . 'stunnel.conf';
    }
}

/* sc_stunnel_pid_path($modern_dir)
 *   Absolute path where stunnel writes its PID file. */
if (!function_exists('sc_stunnel_pid_path')) {
    function sc_stunnel_pid_path($modern_dir) {
        $real = realpath($modern_dir);
        if (!$real) $real = $modern_dir;
        return rtrim($real, '/\\') . DIRECTORY_SEPARATOR . 'stunnel.pid';
    }
}

/* sc_read_stunnel_target($conf_path)
 *   Read an existing stunnel.conf and return the connect host:port. */
if (!function_exists('sc_read_stunnel_target')) {
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
            /* skip comments and section headers. */
            if ($line === '' || $line[0] === ';' || $line[0] === '#'
                || $line[0] === '[') {
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

/* sc_generate_stunnel_conf($conf_path, $pid_path, $ca_cert, $local_port,
 *                          $remote_host, $remote_port)
 *   Write a fresh stunnel.conf for the given target. */
if (!function_exists('sc_generate_stunnel_conf')) {
    function sc_generate_stunnel_conf($conf_path, $pid_path, $ca_cert,
                                      $local_port, $remote_host,
                                      $remote_port) {
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

/* sc_pid_alive($pid)
 *   Is the PID still a stunnel process? Uses tasklist on Windows,
 *   ps on Unix. This avoids trusting stale pid files that now point
 *   at an unrelated process. */
if (!function_exists('sc_pid_alive')) {
    function sc_pid_alive($pid) {
        $pid = (int)$pid;
        if ($pid <= 0) {
            return false;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = array();
            $rc = 0;
            /* /FI "PID eq N" filters; /NH skips header. */
            @exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL',
                  $output, $rc);
            if (!is_array($output) || count($output) === 0) {
                return false;
            }
            /* tasklist prints "INFO: No tasks..." when no match. */
            $line = implode("\n", $output);
            if (strpos($line, 'No tasks') !== false) {
                return false;
            }
            return (stripos($line, 'stunnel') !== false);
        }
        /* Unix: verify the command name, not just PID existence. */
        $output = array();
        $rc = 0;
        @exec('ps -p ' . $pid . ' -o comm= 2>/dev/null', $output, $rc);
        if ($rc !== 0 || !is_array($output) || count($output) === 0) {
            return false;
        }
        return (stripos(implode("\n", $output), 'stunnel') !== false);
    }
}

/* sc_stunnel_is_running($pid_path)
 *   Check if the stunnel process (by PID file) is alive. */
if (!function_exists('sc_stunnel_is_running')) {
    function sc_stunnel_is_running($pid_path) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = array();
            @exec('tasklist /FI "IMAGENAME eq stunnel.exe" 2>NUL', $output);
            foreach ($output as $line) {
                if (stripos($line, 'stunnel.exe') !== false) {
                    return 999999; /* dummy PID */
                }
            }
            return false;
        }
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

/* sc_stunnel_stop($pid_path)
 *   Stop a running stunnel by killing its PID. */
if (!function_exists('sc_stunnel_stop')) {
    function sc_stunnel_stop($pid_path) {
        $pid = sc_stunnel_is_running($pid_path);
        if ($pid === false) {
            /* also clear stale pid file. */
            if (is_file($pid_path)) {
                @unlink($pid_path);
            }
            return true;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec('taskkill /F /PID ' . $pid . ' 2>NUL',
                  $output, $rc);
        } else {
            @exec('kill ' . $pid . ' 2>/dev/null', $output, $rc);
        }
        /* give it a moment to die. */
        usleep(200000);
        if (is_file($pid_path)) {
            @unlink($pid_path);
        }
        return (sc_stunnel_is_running($pid_path) === false);
    }
}

/* sc_stunnel_start($stunnel_exe, $conf_path, $pid_path)
 *   Launch stunnel with the given config file. */
if (!function_exists('sc_stunnel_start')) {
    function sc_stunnel_start($stunnel_exe, $conf_path, $pid_path) {
        if (!is_file($stunnel_exe)) {
            return false;
        }
        if (!is_file($conf_path)) {
            return false;
        }
        /* quote paths for Windows safety (spaces in "Program Files"). */
        $cmd = '"' . $stunnel_exe . '" "' . $conf_path . '"';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            /* start detached: use "start /B" via cmd /c. */
            $cmd = 'cmd /c start /B "" ' . $cmd;
            @exec($cmd);
        } else {
            @exec($cmd . ' >/dev/null 2>&1 &');
        }
        /* wait up to ~3 seconds for the PID file to appear. */
        for ($i = 0; $i < 30; $i++) {
            usleep(100000);
            if (sc_stunnel_is_running($pid_path) !== false) {
                return true;
            }
        }
        return false;
    }
}

/* sc_ensure_tunnel($target, $cfg, $modern_dir)
 *   Ensure a stunnel tunnel is running and targeting $target.
 *   Generates a fresh config and restarts stunnel if the target
 *   has changed.
 *
 *   The CA path in CONF.ini is relative to the project root (the
 *   directory that holds CONF.ini). ModernNetwork/ is a direct
 *   child of that root, so its parent -- the *first* ancestor
 *   above $modern_dir -- is the project root. We intentionally
 *   do NOT call dirname() on $modern_dir again: $modern_dir
 *   already ends with "ModernNetwork" and sc_resolve_path()
 *   treats its argument as a directory before joining, so an
 *   extra dirname() walked one level too high and produced paths
 *   like ".../Server/../../...". */
if (!function_exists('sc_ensure_tunnel')) {
    function sc_ensure_tunnel($target, $cfg, $modern_dir) {
        $conf_path = sc_stunnel_conf_path($modern_dir);
        $pid_path  = sc_stunnel_pid_path($modern_dir);
        $ca_cert   = sc_resolve_path($cfg['ca_cert'],
                                     dirname(__FILE__));
        $current   = sc_read_stunnel_target($conf_path);
        $matches   = !empty($current)
                  && $current['host'] === $target['host']
                  && (int)$current['port'] === (int)$target['port'];
        if ($matches && sc_stunnel_is_running($pid_path) !== false) {
            return true;
        }
        /* stop any stale instance. */
        sc_stunnel_stop($pid_path);
        /* generate fresh config. */
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

/* SC_ChunkedParser
 *   Parses chunked transfer-encoding streams in real-time. */
if (!class_exists('SC_ChunkedParser')) {
    class SC_ChunkedParser {
        private $buffer = '';
        private $state = 0;       /* 0 = reading size, 1 = reading data */
        private $chunk_size = 0;

        public function feed($data, $callback) {
            $this->buffer .= $data;
            while (true) {
                if ($this->state === 0) {
                    $pos = strpos($this->buffer, "\r\n");
                    if ($pos === false) {
                        break;
                    }
                    $line = substr($this->buffer, 0, $pos);
                    $semi = strpos($line, ';');
                    if ($semi !== false) {
                        $line = substr($line, 0, $semi);
                    }
                    $line = trim($line);
                    if ($line === '') {
                        $this->buffer = substr($this->buffer, $pos + 2);
                        continue;
                    }
                    $this->chunk_size = hexdec($line);
                    $this->buffer = substr($this->buffer, $pos + 2);
                    $this->state = 1;
                }
                if ($this->state === 1) {
                    if (strlen($this->buffer) < $this->chunk_size + 2) {
                        break;
                    }
                    $chunk_data = substr($this->buffer, 0,
                                          $this->chunk_size);
                    $this->buffer = substr($this->buffer,
                                            $this->chunk_size + 2);
                    $this->state = 0;
                    if ($this->chunk_size > 0) {
                        call_user_func($callback, $chunk_data);
                    } else {
                        break;
                    }
                }
            }
        }
    }
}

/* sc_http_send_raw($port, $method, $host, $path, $headers, $body,
 *                  $timeout, $stream_callback = null,
 *                  $connect_host = '127.0.0.1')
 *   Send a raw HTTP request over a TCP socket and read the full
 *   response. Returns array('status','headers','body') or
 *   array('error'). */
if (!function_exists('sc_http_send_raw')) {
    function sc_http_send_raw($port, $method, $host, $path, $headers,
                              $body, $timeout, $stream_callback = null,
                              $connect_host = '127.0.0.1') {
        $errno = 0;
        $errstr = '';
        $chost = (is_string($connect_host) && $connect_host !== '')
                 ? $connect_host : '127.0.0.1';
        $fp = @fsockopen($chost, (int)$port, $errno, $errstr,
                          (int)$timeout);
        if ($fp === false) {
            return array('error' => 'connection_failed');
        }
        @stream_set_timeout($fp, (int)$timeout);
        /* build request. */
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
        /* read response. */
        $raw = '';
        $header_end = false;
        $headers_str = '';
        $body_buffered = '';

        $is_chunked = false;
        $chunked_parser = null;

        while (!@feof($fp)) {
            if (function_exists('connection_aborted')
                && connection_aborted()) {
                break;
            }
            $chunk = @fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }

            if (!$header_end) {
                $raw .= $chunk;
                $pos = strpos($raw, "\r\n\r\n");
                if ($pos !== false) {
                    $header_end = true;
                    $headers_str = substr($raw, 0, $pos);
                    $body_buffered = substr($raw, $pos + 4);

                    if (stripos($headers_str,
                                'Transfer-Encoding: chunked') !== false) {
                        $is_chunked = true;
                        $chunked_parser = new SC_ChunkedParser();
                    }

                    if ($stream_callback !== null) {
                        if ($is_chunked) {
                            $chunked_parser->feed($body_buffered,
                                                   $stream_callback);
                        } else {
                            if (strlen($body_buffered) > 0) {
                                call_user_func($stream_callback,
                                                $body_buffered);
                            }
                        }
                    }
                }
            } else {
                if ($stream_callback !== null) {
                    if ($is_chunked) {
                        $chunked_parser->feed($chunk, $stream_callback);
                    } else {
                        call_user_func($stream_callback, $chunk);
                    }
                }
                $raw .= $chunk;
            }
        }
        @fclose($fp);
        /* parse status line. */
        $sep = strpos($raw, "\r\n");
        if ($sep === false) {
            return array('error' => 'malformed_response');
        }
        $status_line = substr($raw, 0, $sep);
        if (!preg_match('#^HTTP/\S+\s+(\d+)#', $status_line, $m)) {
            return array('error' => 'malformed_response');
        }
        $status = (int)$m[1];
        /* parse headers and body. */
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
        return array('status' => $status, 'headers' => $hdrs,
                     'body' => $body_out);
    }
}

/* sc_http_request($path, $body, $headers)
 *   Send an HTTP request through the ModernNetwork tunnel. */
if (!function_exists('sc_http_request')) {
    function sc_http_request($path, $body, $headers) {
        /* resolve project root (where CONF.ini lives) and
         * ModernNetwork dir. */
        $ini_path = dirname(__FILE__) . '/../CONF.ini';
        $modern_dir = dirname(__FILE__);
        $cfg = sc_load_modern_config($ini_path);
        if (empty($cfg) || $cfg['api_base'] === ''
            || $cfg['stunnel'] === '' || $cfg['ca_cert'] === '') {
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
        if (isset($resp['error'])
            && $resp['error'] === 'connection_failed') {
            /* tunnel died between ensure and connect; try once more. */
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
