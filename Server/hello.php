<?php
/**
 * stoneChat Server hello module.
 *
 * Trivial greeting function used to demonstrate the Server/ include path.
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_hello')) {
    /**
     * Return the canonical stoneChat greeting.
     *
     * @return string The greeting.
     */
    function sc_hello() {
        return 'Hello, stoneChat!';
    }
}
