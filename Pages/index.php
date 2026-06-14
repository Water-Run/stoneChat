<?php
/**
 * stoneChat entry page.
 *
 * Renders the XHTML 1.0 Transitional placeholder page compatible with IE6.
 * Loads Server/config.php and Server/hello.php to verify the include layout.
 *
 * Note: This page is text-only by design. Assets/logo.png exists in the
 * repository but is not referenced here because the PHP built-in server
 * (with -t Pages) cannot serve files outside Pages/. Future commits may
 * route assets properly.
 */

require_once dirname(__FILE__) . '/../Server/config.php';
require_once dirname(__FILE__) . '/../Server/hello.php';

header('Content-Type: text/html; charset=UTF-8');
$hello = sc_hello();
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<title>stoneChat</title>
<style type="text/css">
body { font-family: Tahoma, Verdana, sans-serif; background: #f4f1ea; color: #333; margin: 0; padding: 0; }
.wrap { width: 600px; margin: 60px auto; text-align: center; }
h1 { font-size: 28px; margin: 20px 0 10px 0; }
p.placeholder { font-size: 14px; color: #666; margin: 0 0 30px 0; }
p.hello { font-family: "Courier New", monospace; font-size: 13px; color: #2a6f2a; }
</style>
</head>
<body>
<div class="wrap">
<h1>stoneChat</h1>
<p class="placeholder">(a caveman peeking at modern technology) &mdash; placeholder page</p>
<p class="hello"><?php echo htmlspecialchars($hello, ENT_QUOTES, 'UTF-8'); ?></p>
</div>
</body>
</html>
