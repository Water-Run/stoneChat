<?php
/**
 * stoneChat PHP built-in server router.
 *
 * The PHP built-in server treats the current directory (.) as the doc
 * root. Pages/, Server/, Assets/, ModernNetwork/, HISTORY/, and CONF.ini
 * all live at the project root.
 *
 * Routing rules:
 *   /                  -> 302 to /Pages/index.htm (or /Pages/index.php fallback)
 *   /Pages/...         -> serve static file from Pages/
 *   /Server/api/*.php  -> execute as PHP (cwd = that file's directory)
 *   /Server/langs/*.php -> execute as PHP
 *   /Assets/...        -> serve static file with MIME type
 *   /ModernNetwork/... -> serve static file (cacert.pem, README, etc.)
 *   /HISTORY/...       -> serve static file (text logs, etc.)
 *   /CONF.ini          -> serve as text/plain
 *   anything else      -> return false (PHP server returns 404)
 */

$path = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$qpos = strpos($path, '?');
if ($qpos !== false) {
    $path = substr($path, 0, $qpos);
}
$path = '/' . ltrim($path, '/');

// 1) Root: redirect to Pages entry
if ($path === '/' || $path === '') {
    header('Location: /Pages/index.htm');
    return true;
}

// 2) Pages/ subtree (htm, js, css, the index.php fallback)
if (strpos($path, '/Pages/') === 0) {
    $file = realpath('.' . $path);
    if ($file !== false && is_file($file) && is_readable($file)) {
        $ext = strtolower(strrchr($file, '.'));
        if ($ext === '.php') {
            // Execute in its own dir so dirname(__FILE__) works.
            chdir(dirname($file));
            require $file;
            return true;
        }
        return false; // let PHP serve as static
    }
    return false; // 404
}

// 3) Server-side PHP endpoints
$php_prefixes = array(
    '/Server/api/'   => 'Server/api/',
    '/Server/langs/' => 'Server/langs/',
);
foreach ($php_prefixes as $prefix => $target_prefix) {
    if (strpos($path, $prefix) === 0) {
        $file = realpath($target_prefix . substr($path, strlen($prefix)));
        if ($file !== false && is_file($file) && is_readable($file)) {
            chdir(dirname($file));
            require $file;
            return true;
        }
        return false; // 404
    }
}

// 4) Server/library PHP files (config.php, auth.php, llm.php, etc.)
//    Only exposed when called as a PHP endpoint.  These are includes, not
//    real endpoints -- returning 404 is fine.
if (strpos($path, '/Server/') === 0) {
    $file = realpath('.' . $path);
    if ($file !== false && is_file($file) && is_readable($file) && strtolower(strrchr($file, '.')) === '.php') {
        chdir(dirname($file));
        require $file;
        return true;
    }
    return false; // 404
}

// 5) Static assets (Assets/, ModernNetwork/, HISTORY/)
$static_prefixes = array(
    '/Assets/'        => 'Assets/',
    '/ModernNetwork/' => 'ModernNetwork/',
    '/HISTORY/'       => 'HISTORY/',
);
foreach ($static_prefixes as $prefix => $target_prefix) {
    if (strpos($path, $prefix) === 0) {
        $file = $target_prefix . substr($path, strlen($prefix));
        if (is_file($file) && is_readable($file)) {
            return false; // let PHP serve as static with auto MIME
        }
        return false; // 404
    }
}

// 6) CONF.ini at root
if ($path === '/CONF.ini' && is_file('CONF.ini')) {
    return false; // let PHP serve as static
}

// 7) Anything else: 404
header('HTTP/1.0 404 Not Found');
echo '404 Not Found';
return true;
