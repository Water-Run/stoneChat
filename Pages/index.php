<?php
/* -------------------------------------------------------------------------
 * stoneChat / Pages/index.php
 *
 * PHP built-in server (`php -S host:port -t Pages`) looks for
 * `index.php` first when serving directory requests. This file
 * simply redirects to `index.htm` (the real entry point). Delete
 * this file and PHP will serve `index.htm` directly.
 * ------------------------------------------------------------------------- */

$sc_boot_check = dirname(__FILE__) . '/../Server/boot_check.php';
if (is_file($sc_boot_check)) {
    require_once $sc_boot_check;
    if (function_exists('sc_strict_environment_check')) {
        sc_strict_environment_check();
    }
}

header('Location: index.htm');
exit;
