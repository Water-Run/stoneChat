<?php
/* -------------------------------------------------------------------------
 * stoneChat / Pages/router.php
 *
 * PHP built-in server router. The server treats the current
 * directory (.) as the doc root. Pages/, Server/, Assets/,
 * ModernNetwork/, HISTORY/, and CONF.ini all live at the project
 * root.
 *
 * Routing rules:
 *   /                  -> 302 to /Pages/index.htm
 *   /Pages/...         -> serve static file from Pages/
 *   /Server/api/*.php  -> execute as PHP (cwd = that file's dir)
 *   /Server/langs/*.php -> execute as PHP
 *   /Assets/...        -> serve static file with MIME type
 *   /ModernNetwork/... -> serve static file (cacert.pem, README, ...)
 *   /HISTORY/...       -> 404 (runtime data is private)
 *   /CONF.ini          -> 404 (config can contain secrets)
 *   anything else      -> return false (PHP server returns 404)
 *
 * Public helpers (sc_-prefixed, function_exists guarded):
 *   sc_router_not_found()               emit 404 header and response
 *   sc_router_is_under($file, $base)    path traversal protection
 *   sc_router_no_cache()                disable browser cache headers
 *   sc_router_content_type($ext)        get content type for extension
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

if (!function_exists('sc_router_not_found')) {
    function sc_router_not_found() {
        header('HTTP/1.0 404 Not Found');
        echo '404 Not Found';
        return true;
    }
}

if (!function_exists('sc_router_is_under')) {
    function sc_router_is_under($file, $base) {
        $file_real = @realpath($file);
        $base_real = @realpath($base);
        if ($file_real === false || $base_real === false) {
            return false;
        }
        $file_norm = str_replace('\\', '/', $file_real);
        $base_norm = rtrim(str_replace('\\', '/', $base_real), '/');
        return strpos($file_norm . '/', $base_norm . '/') === 0;
    }
}

if (!function_exists('sc_router_no_cache')) {
    function sc_router_no_cache() {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Sat, 1 Jan 2000 00:00:00 GMT');
    }
}

if (!function_exists('sc_router_content_type')) {
    function sc_router_content_type($ext) {
        if ($ext === '.htm' || $ext === '.html') {
            return 'text/html; charset=UTF-8';
        }
        if ($ext === '.js') {
            return 'text/javascript; charset=UTF-8';
        }
        if ($ext === '.css') {
            return 'text/css; charset=UTF-8';
        }
        if ($ext === '.txt' || $ext === '.org') {
            return 'text/plain; charset=UTF-8';
        }
        return '';
    }
}

$sc_boot_check = dirname(__FILE__) . '/../Server/boot_check.php';
if (is_file($sc_boot_check)) {
    require_once $sc_boot_check;
    if (function_exists('sc_strict_environment_check')) {
        sc_strict_environment_check();
    }
}

/* 0) favicon.ico: short-circuit before the modern-interlude logic so
 *    the browser's automatic /favicon.ico probe never triggers a
 *    Super-Modern redirect. If a real icon is present, serve it;
 *    otherwise respond 204 (No Content) and let the browser give up. */
$sc_raw_req = isset($_SERVER['REQUEST_URI'])
            ? (string)$_SERVER['REQUEST_URI'] : '';
$sc_raw_q = strpos($sc_raw_req, '?');
$sc_raw_path = ($sc_raw_q === false) ? $sc_raw_req
              : substr($sc_raw_req, 0, $sc_raw_q);
$sc_raw_path = '/' . ltrim($sc_raw_path, '/');
if ($sc_raw_path === '/favicon.ico') {
    $sc_fav = dirname(__FILE__) . '/../Assets/logo.ico';
    if (is_file($sc_fav) && is_readable($sc_fav)) {
        header('Content-Type: image/x-icon');
        header('Content-Length: ' . filesize($sc_fav));
        readfile($sc_fav);
    } else {
        header('HTTP/1.0 204 No Content');
    }
    return true;
}

/* ---- Modern-Windows "Super-Modern-HTML" interlude ----------------
 * When the host is Windows 10 1809 (build 17763) or newer AND the
 * request is for a real HTML page (not the super-modern.htm itself,
 * not an API/static asset), AND the requesting browser is NOT a
 * legacy Internet Explorer, redirect to the modern splash page.
 *
 * The client-browser guard is essential: sc_is_modern_windows() checks
 * the *server* OS, not the *client* browser. A genuine XP/IE6 client
 * reaching a Win10 host must NOT be redirected to super-modern.htm
 * because that page uses HTML5 tags (<main>, media queries) that IE6
 * cannot render.  Any UA containing "MSIE" or "Trident/" is IE and
 * must be served the classic retro layout directly.
 *
 * The splash page sets sc_super_modern_seen=1 so subsequent page loads
 * in the same browser session skip it. */
$sc_ua = isset($_SERVER['HTTP_USER_AGENT'])
         ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
$sc_client_is_ie = (strpos($sc_ua, 'MSIE') !== false
                    || strpos($sc_ua, 'Trident/') !== false);
$sc_is_modern = (!$sc_client_is_ie
                 && function_exists('sc_is_modern_windows')
                 && sc_is_modern_windows());
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
$sc_html_exists     = false;
if ($sc_path_for_check === '/') {
    $sc_html_exists = true;
} elseif ($sc_is_html_page) {
    $sc_html_file = realpath('.' . $sc_path_for_check);
    if ($sc_html_file !== false
        && sc_router_is_under($sc_html_file, 'Pages')
        && is_file($sc_html_file) && is_readable($sc_html_file)) {
        $sc_html_exists = true;
    }
}
if ($sc_is_modern && !$sc_already_seen
    && $sc_is_html_page && $sc_html_exists && !$sc_is_super_modern) {
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

/* 1) Root: redirect to Pages entry. */
if ($path === '/' || $path === '') {
    sc_router_no_cache();
    header('Location: /Pages/index.htm');
    return true;
}

/* 2) Pages/ subtree (htm, js, css, the index.php fallback). */
if (strpos($path, '/Pages/') === 0) {
    $file = realpath('.' . $path);
    if ($file !== false && sc_router_is_under($file, 'Pages')
        && is_file($file) && is_readable($file)) {
        $ext = strtolower(strrchr($file, '.'));
        if ($ext === '.php') {
            /* execute in its own dir so dirname(__FILE__) works. */
            chdir(dirname($file));
            require $file;
            return true;
        }
        $ctype = sc_router_content_type($ext);
        if ($ctype !== '') {
            header('Content-Type: ' . $ctype);
            sc_router_no_cache();
            readfile($file);
            return true;
        }
        return false; /* let PHP serve as static. */
    }
    return sc_router_not_found();
}

/* 3) Server-side PHP endpoints. */
$php_prefixes = array(
    '/Server/api/'   => 'Server/api/',
    '/Server/langs/' => 'Server/langs/',
);
foreach ($php_prefixes as $prefix => $target_prefix) {
    if (strpos($path, $prefix) === 0) {
        $file = realpath($target_prefix . substr($path, strlen($prefix)));
        if ($file !== false && sc_router_is_under($file, $target_prefix)
            && is_file($file) && is_readable($file)) {
            chdir(dirname($file));
            require $file;
            return true;
        }
        return sc_router_not_found();
    }
}

/* 4) Server/library PHP files (config.php, auth.php, llm.php, etc.).
 *    Only exposed when called as a PHP endpoint. These are includes,
 *    not real endpoints -- returning 404 is fine. */
if (strpos($path, '/Server/') === 0) {
    return sc_router_not_found();
}

/* 5) Static assets (Assets/, ModernNetwork/). */
$static_prefixes = array(
    '/Assets/'        => 'Assets/',
    '/ModernNetwork/' => 'ModernNetwork/',
);
foreach ($static_prefixes as $prefix => $target_prefix) {
    if (strpos($path, $prefix) === 0) {
        if ($prefix === '/ModernNetwork/') {
            $name = basename($path);
            if ($name === 'stunnel.conf' || $name === 'stunnel.pid') {
                return sc_router_not_found();
            }
        }
        $file = $target_prefix . substr($path, strlen($prefix));
        if (sc_router_is_under($file, $target_prefix)
            && is_file($file) && is_readable($file)) {
            return false; /* let PHP serve as static with auto MIME. */
        }
        return sc_router_not_found();
    }
}

/* 6) Private runtime/config files. */
if ($path === '/CONF.ini' || strpos($path, '/HISTORY/') === 0) {
    return sc_router_not_found();
}

/* 7) Anything else: 404. */
return sc_router_not_found();
