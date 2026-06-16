<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/hello.php
 *
 * Trivial greeting function used to demonstrate the Server/ include
 * path. PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_hello()
 *   Canonical stoneChat greeting. */
if (!function_exists('sc_hello')) {
    function sc_hello() {
        return 'Hello, stoneChat!';
    }
}
