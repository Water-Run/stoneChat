<?php
/* -------------------------------------------------------------------------
 * stoneChat / Pages/editor.php
 * Online configuration editor for CONF.ini. Only accessible if enabled.
 * ------------------------------------------------------------------------- */
require_once dirname(__FILE__) . '/../Server/api/auth.php';
require_once dirname(__FILE__) . '/../Server/api/config.php';

/* Require authentication */
if (function_exists('sc_api_auth_verify')) {
    $auth = sc_api_auth_verify();
    if (!$auth['ok']) {
        header('Location: index.htm');
        exit;
    }
}

/* Check if enabled */
$ini_path = sc_api_config_ini_path();
$cfg = sc_load_config($ini_path);
if (!isset($cfg['ui']['allow_online_editor']) || sc_api_config_truthy($cfg['ui']['allow_online_editor']) !== true) {
    die('<html><body><script>alert("Online config editor is DISABLED in CONF.ini (allow_online_editor = false).");window.close();</script></body></html>');
}

$message = '';
$message_class = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config_content'])) {
    if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()) {
        $content = stripslashes($_POST['config_content']);
    } else {
        $content = $_POST['config_content'];
    }
    
    /* normalize line endings */
    $content = str_replace(array("\r\n", "\r"), "\n", $content);
    $content = str_replace("\n", "\r\n", $content);
    
    $bytes = @file_put_contents($ini_path, $content);
    if ($bytes !== false) {
        $message = 'Configuration saved successfully! Please click "Reload config" in the chat page.';
        $message_class = 'success';
    } else {
        $message = 'Failed to save configuration. Check file permissions.';
        $message_class = 'error';
    }
}

$content = @file_get_contents($ini_path);
if ($content === false) {
    $content = '; Could not read CONF.ini';
}
?>
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="robots" content="noindex,nofollow" />
<title>stoneChat - CONF.ini Editor</title>
<link rel="stylesheet" type="text/css" href="css/main.css" />
<style type="text/css">
  .editor-wrap { max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border: 1px solid #ccc; box-shadow: 2px 2px 5px rgba(0,0,0,0.1); }
  .editor-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
  .editor-warning { background: #ffebeb; border: 1px solid #ffcccc; color: #cc0000; padding: 10px; margin-bottom: 15px; font-size: 12px; }
  textarea { width: 100%; height: 400px; font-family: monospace; font-size: 13px; line-height: 1.4; border: 1px solid #999; padding: 5px; box-sizing: border-box; }
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
    <strong>WARNING: Remote Code Execution (RCE) Risk</strong><br />
    This online editor has full write access to the server's configuration file.
    An attacker who discovers your password could modify paths or other settings to compromise the server.
    For maximum security, disable <code>allow_online_editor</code> and edit the file locally.
  </div>
  
  <?php if ($message !== ''): ?>
    <div class="editor-msg <?php echo $message_class; ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>
  
  <form method="post" action="editor.php">
    <textarea name="config_content" spellcheck="false"><?php echo htmlspecialchars($content); ?></textarea>
    <div class="editor-actions">
      <button type="button" class="btn" onclick="window.close()">Close Window</button>
      <button type="submit" class="btn">Save Configuration</button>
    </div>
  </form>
</div>
</body>
</html>
