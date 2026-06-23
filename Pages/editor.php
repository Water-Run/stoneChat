<?php
/* -------------------------------------------------------------------------
 * stoneChat / Pages/editor.php
 *
 * Optional online CONF.ini editor. Disabled unless
 * [ui] allow_online_editor = true.
 *
 * This page intentionally includes library files only. The JSON API files
 * are entry points and would emit responses immediately if included here.
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */
require_once dirname(__FILE__) . '/../Server/config.php';
require_once dirname(__FILE__) . '/../Server/auth.php';

if (!function_exists('sc_editor_ini_path')) {
    function sc_editor_ini_path() {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
             . DIRECTORY_SEPARATOR . 'CONF.ini';
    }
}

if (!function_exists('sc_editor_truthy')) {
    function sc_editor_truthy($value) {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string)$value));
        return ($text === '1' || $text === 'true' || $text === 'yes'
                || $text === 'on');
    }
}

if (!function_exists('sc_editor_cookie_name')) {
    function sc_editor_cookie_name($cfg) {
        if (is_array($cfg) && isset($cfg['auth'])
            && is_array($cfg['auth'])
            && isset($cfg['auth']['cookie_name'])
            && (string)$cfg['auth']['cookie_name'] !== '') {
            return (string)$cfg['auth']['cookie_name'];
        }
        return 'sc_auth';
    }
}

if (!function_exists('sc_editor_is_authorized')) {
    function sc_editor_is_authorized($cfg) {
        $name = sc_editor_cookie_name($cfg);
        $token = '';
        if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
            $token = $_COOKIE[$name];
        }
        if ($token === '' && isset($_COOKIE['sc_session'])
            && is_string($_COOKIE['sc_session'])) {
            $token = $_COOKIE['sc_session'];
        }
        if ($token === '' || !function_exists('sc_auth_token_context')) {
            return false;
        }
        $ctx = sc_auth_token_context($token, $cfg);
        if (empty($ctx['ok'])) {
            return false;
        }
        $username = isset($ctx['username']) ? (string)$ctx['username'] : '';
        if (!function_exists('sc_auth_can_edit_config')
            || !sc_auth_can_edit_config($cfg, $username)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('sc_editor_h')) {
    function sc_editor_h($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

$ini_path = sc_editor_ini_path();
$cfg = sc_load_config($ini_path);
if (!is_array($cfg)) {
    $cfg = array();
}

if (!sc_editor_is_authorized($cfg)) {
    header('Location: index.htm');
    exit;
}

$enabled = false;
if (isset($cfg['ui']) && is_array($cfg['ui'])
    && isset($cfg['ui']['allow_online_editor'])) {
    $enabled = sc_editor_truthy($cfg['ui']['allow_online_editor']);
}
if (!$enabled) {
    echo '<!doctype html><html><body><script type="text/javascript">'
       . 'alert("Online config editor is DISABLED in CONF.ini '
       . '(allow_online_editor = false).");window.close();'
       . '</script></body></html>';
    exit;
}

$message = '';
$message_class = '';
if (isset($_SERVER['REQUEST_METHOD'])
    && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST'
    && isset($_POST['config_content'])) {
    if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()) {
        $content = stripslashes($_POST['config_content']);
    } else {
        $content = $_POST['config_content'];
    }

    $content = str_replace(array("\r\n", "\r"), "\n", (string)$content);
    $content = str_replace("\n", "\r\n", $content);

    $bytes = @file_put_contents($ini_path, $content);
    if ($bytes !== false) {
        $message = 'Configuration saved successfully. Click Reload config in the chat page.';
        $message_class = 'success';
    } else {
        $message = 'Failed to save configuration. Check file permissions.';
        $message_class = 'error';
    }
}

$content = @file_get_contents($ini_path);
if (!is_string($content)) {
    $content = '; Could not read CONF.ini';
}
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="robots" content="noindex,nofollow" />
<title>stoneChat - CONF.ini Editor</title>
<link rel="stylesheet" type="text/css" href="css/main.css" />
<style type="text/css">
  .editor-wrap { max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border: 1px solid #ccc; }
  .editor-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
  .editor-warning { background: #ffebeb; border: 1px solid #ffcccc; color: #cc0000; padding: 10px; margin-bottom: 15px; font-size: 12px; }
  textarea { width: 100%; height: 400px; font-family: "Courier New", monospace; font-size: 13px; line-height: 1.4; border: 1px solid #999; padding: 5px; }
  .editor-actions { margin-top: 15px; text-align: right; }
  .editor-msg { padding: 10px; margin-bottom: 15px; font-weight: bold; }
  .editor-msg.success { background: #e6f7e6; color: #006600; border: 1px solid #b3e6b3; }
  .editor-msg.error { background: #ffebeb; color: #cc0000; border: 1px solid #ffcccc; }
  .btn { padding: 5px 15px; font-size: 12px; cursor: pointer; background: #eee; border: 1px solid #999; }
</style>
</head>
<body>
<div class="editor-wrap">
  <div class="editor-title">CONF.ini Editor</div>

  <div class="editor-warning">
    <strong>WARNING: Remote Code Execution Risk</strong><br />
    This editor writes server configuration. Enable it only on a trusted LAN,
    and turn <code>allow_online_editor</code> off when you are done.
  </div>

  <?php if ($message !== '') { ?>
    <div class="editor-msg <?php echo sc_editor_h($message_class); ?>"><?php echo sc_editor_h($message); ?></div>
  <?php } ?>

  <form method="post" action="editor.php">
    <textarea name="config_content" spellcheck="false"><?php echo sc_editor_h($content); ?></textarea>
    <div class="editor-actions">
      <button type="button" class="btn" onclick="window.close()">Close Window</button>
      <button type="submit" class="btn">Save Configuration</button>
    </div>
  </form>
</div>
</body>
</html>
