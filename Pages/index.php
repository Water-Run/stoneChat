<?php
/**
 * stoneChat default entry.
 *
 * PHP built-in server (`php -S host:port -t Pages`) looks for `index.php`
 * first when serving directory requests. This file simply redirects to
 * `index.htm` (the real entry point). Delete this file and PHP will
 * serve `index.htm` directly.
 */

header('Location: index.htm');
exit;
