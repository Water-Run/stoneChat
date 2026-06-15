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

// Pull in boot_check (provides sc_is_modern_windows) so the modern
// super-modern.htm interlude can be triggered for capable hosts.
$sc_boot_check = dirname(__FILE__) . '/../Server/boot_check.php';
if (is_file($sc_boot_check)) {
    require_once $sc_boot_check;
}

// 0) favicon.ico: short-circuit before the modern-interlude logic so
//    the browser's automatic /favicon.ico probe never triggers a
//    Super-Modern redirect. If a real icon is present, serve it;
//    otherwise respond 204 (No Content) and let the browser give up.
$sc_raw_req = isset($_SERVER['REQUEST_URI'])
            ? (string)$_SERVER['REQUEST_URI'] : '';
$sc_raw_q = strpos($sc_raw_req, '?');
$sc_raw_path = ($sc_raw_q === false) ? $sc_raw_req
              : substr($sc_raw_req, 0, $sc_raw_q);
$sc_raw_path = '/' . ltrim($sc_raw_path, '/');
if ($sc_raw_path === '/favicon.ico') {
    $sc_fav = dirname(__FILE__) . '/../Assets/favicon.ico';
    if (is_file($sc_fav) && is_readable($sc_fav)) {
        header('Content-Type: image/x-icon');
        header('Content-Length: ' . filesize($sc_fav));
        readfile($sc_fav);
    } else {
        header('HTTP/1.0 204 No Content');
    }
    return true;
}

// ---- Modern-Windows "Super-Modern-HTML" interlude --------------
// When the host is Windows 10 1809 (build 17763) or newer AND the
// request is for a real HTML page (not the super-modern.htm itself,
// not an API/static asset), redirect the browser to the modern
// splash page. The splash page sets a session cookie
// (sc_super_modern_seen=1) before the 3-second countdown finishes
// so subsequent page loads in the same browser session skip it.
$sc_is_modern = function_exists('sc_is_modern_windows')
                ? sc_is_modern_windows() : false;
if ($sc_is_modern && empty($_COOKIE['sc_modern'])) {
    // One year, scoped to the whole site. HttpOnly is unnecessary
    // (the client never reads this value via JS).
    setcookie('sc_modern', '1', time() + 31536000, '/', '', false, true);
    $_COOKIE['sc_modern'] = '1';
}
$sc_already_seen    = !empty($_COOKIE['sc_super_modern_seen']);
$sc_path_for_check  = isset($_SERVER['REQUEST_URI'])
                      ? (string)$_SERVER['REQUEST_URI'] : '';
$sc_qpos = strpos($sc_path_for_check, '?');
if ($sc_qpos !== false) {
    $sc_path_for_check = substr($sc_path_for_check, 0, $sc_qpos);
}
$sc_path_for_check  = '/' . ltrim($sc_path_for_check, '/');
$sc_is_super_modern = (strpos($sc_path_for_check,
                              '/Pages/super-modern.htm') === 0);
$sc_is_html_page    = ($sc_path_for_check === '/'
                      || preg_match('/\.(htm|html)$/i',
                                    $sc_path_for_check));
if ($sc_is_modern && !$sc_already_seen
    && $sc_is_html_page && !$sc_is_super_modern) {
    $sc_next = $sc_path_for_check;
    if (!empty($_SERVER['QUERY_STRING'])) {
        $sc_next .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: /Pages/super-modern.htm?next='
           . urlencode($sc_next));
    return true;
}

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
