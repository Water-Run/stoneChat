<?php
/* -------------------------------------------------------------------------
 * stoneChat / Pages/editor.php
 *
 * Optional CONF.ini editor. Requires:
 *   [ui] allow_online_editor = true
 *   current [User NAME].can_edit_config = true
 *
 * On IE/Windows, the first choice is the old desktop way: the page
 * tries WScript.Shell ActiveX to open CONF.ini in Notepad. The web
 * textarea is kept as a fallback for browsers that cannot start a
 * local desktop program.
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
        $token = sc_editor_session_token($cfg);
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

if (!function_exists('sc_editor_session_token')) {
    function sc_editor_session_token($cfg) {
        $name = sc_editor_cookie_name($cfg);
        $token = '';
        if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
            $token = $_COOKIE[$name];
        }
        if ($token === '' && isset($_COOKIE['sc_session'])
            && is_string($_COOKIE['sc_session'])) {
            $token = $_COOKIE['sc_session'];
        }
        return $token;
    }
}

if (!function_exists('sc_editor_h')) {
    function sc_editor_h($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sc_editor_native_path')) {
    function sc_editor_native_path($ini_path) {
        $real = @realpath($ini_path);
        if ($real === false || !is_file($real)) {
            return (string)$ini_path;
        }
        return $real;
    }
}

if (!function_exists('sc_editor_js_string')) {
    function sc_editor_js_string($text) {
        $text = (string)$text;
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('"', '\\"', $text);
        $text = str_replace(array("\r", "\n"), array('\\r', '\\n'), $text);
        return '"' . $text . '"';
    }
}

if (!function_exists('sc_editor_validate_content')) {
    function sc_editor_validate_content($content, $ini_path) {
        $dir = dirname($ini_path);
        $tmp = @tempnam($dir, 'scini');
        if (!is_string($tmp) || $tmp === '') {
            return false;
        }
        $ok = (@file_put_contents($tmp, (string)$content) !== false);
        if (!$ok) {
            @unlink($tmp);
            return false;
        }
        $parsed = sc_load_config($tmp);
        @unlink($tmp);
        if (!is_array($parsed) || empty($parsed)) {
            return false;
        }
        if (function_exists('sc_validate_config')
            && function_exists('sc_config_fatal_errors')) {
            $errors = sc_validate_config($parsed);
            $fatal = sc_config_fatal_errors($errors);
            if (is_array($fatal) && !empty($fatal)) {
                return false;
            }
        }
        return true;
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
       . 'alert("CONF.ini editor is disabled by [ui] '
       . 'allow_online_editor.");window.close();'
       . '</script></body></html>';
    exit;
}

$message = '';
$message_class = '';
$session_token = sc_editor_session_token($cfg);
$csrf_token = '';
if (function_exists('sc_auth_csrf_token')) {
    $csrf_token = sc_auth_csrf_token($session_token, 'config_editor');
}
$open_native = (isset($_GET['open_native'])
                && (string)$_GET['open_native'] === '1');
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

    $posted_csrf = '';
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $posted_csrf = $_POST['csrf_token'];
    }
    if (!function_exists('sc_auth_csrf_verify')
        || !sc_auth_csrf_verify($session_token, 'config_editor',
                                $posted_csrf)) {
        $message = 'Security check failed. Reload the editor and try again.';
        $message_class = 'error';
    } elseif (!sc_editor_validate_content($content, $ini_path)) {
        $message = 'Configuration was not saved because it is not valid.';
        $message_class = 'error';
    } else {
        $bytes = @file_put_contents($ini_path, $content);
        if ($bytes !== false) {
            $message = 'Configuration saved successfully.';
            $message_class = 'success';
            $cfg = sc_load_config($ini_path);
            $session_token = sc_editor_session_token($cfg);
            if (function_exists('sc_auth_csrf_token')) {
                $csrf_token = sc_auth_csrf_token($session_token,
                                                 'config_editor');
            }
        } else {
            $message = 'Failed to save configuration. Check file permissions.';
            $message_class = 'error';
        }
    }
}

$content = @file_get_contents($ini_path);
if (!is_string($content)) {
    $content = '; Could not read CONF.ini';
}
$native_path = sc_editor_native_path($ini_path);
$native_cmd = 'notepad.exe "' . str_replace('"', '', $native_path) . '"';
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="robots" content="noindex,nofollow" />
<title>stoneChat - CONF.ini Editor</title>
<link rel="stylesheet" type="text/css" href="css/main.css?v=20260711a" />
<style type="text/css">
  body { background: #d4d0c8; color: #000; font-family: Tahoma, "MS Sans Serif", Arial, sans-serif; font-size: 12px; }
  .editor-wrap { width: 760px; margin: 18px auto; padding: 0; background: #ece9d8; border: 2px outset #fff; text-align: left; }
  .editor-title { font-size: 12px; font-weight: bold; color: #fff; background: #0a246a; padding: 4px 6px; }
  .editor-panel { padding: 10px; }
  .editor-warning { background: #ffffe1; border: 1px solid #808080; color: #000; padding: 8px; margin-bottom: 10px; font-size: 12px; }
  textarea { width: 100%; height: 360px; font-family: "Courier New", monospace; font-size: 13px; line-height: 1.35; border: 2px inset #fff; padding: 4px; background: #fff; color: #000; }
  .editor-actions { margin-top: 15px; text-align: right; }
  .editor-native { margin-bottom: 10px; padding: 8px; border: 1px solid #808080; background: #fff; }
  .editor-msg { padding: 8px; margin-bottom: 10px; font-weight: bold; border: 1px solid #808080; background: #fff; }
  .editor-msg.success { color: #006000; }
  .editor-msg.error { color: #a00000; }
  .btn { padding: 3px 14px; font-size: 12px; cursor: pointer; background: #ece9d8; border: 2px outset #fff; font-family: Tahoma, "MS Sans Serif", Arial, sans-serif; }
  .btn:active { border: 2px inset #fff; }
  .editor-small { color: #404040; margin-top: 6px; }
</style>
<script type="text/javascript">
//<![CDATA[
var sc_editor_native_path = <?php echo sc_editor_js_string($native_path); ?>;
var sc_editor_native_cmd = <?php echo sc_editor_js_string($native_cmd); ?>;
var sc_editor_open_on_load = <?php echo $open_native ? 'true' : 'false'; ?>;

function sc_editor_message(text, className) {
  var box = document.getElementById('editor-msg-client');
  if (!box) { return; }
  box.className = 'editor-msg ' + className;
  if (typeof box.innerText !== 'undefined') {
    box.innerText = text;
  } else {
    box.textContent = text;
  }
  box.style.display = 'block';
}

function sc_editor_open_native() {
  try {
    var shell = new ActiveXObject('WScript.Shell');
    shell.Run(sc_editor_native_cmd, 1, false);
    sc_editor_message('Notepad has been asked to open CONF.ini.', 'success');
  } catch (e) {
    sc_editor_message('Could not start Notepad from this browser. Use Start > Run: ' + sc_editor_native_cmd, 'error');
  }
  return false;
}

function sc_editor_page_load() {
  if (sc_editor_open_on_load) {
    sc_editor_open_native();
  }
}
//]]>
</script>
</head>
<body onload="sc_editor_page_load()">
<div class="editor-wrap">
  <div class="editor-title">CONF.ini Editor</div>
  <div class="editor-panel">

  <div class="editor-warning">
    <strong>Notice</strong><br />
    First try the Windows editor. Save the file in Notepad, close it,
    then return to stoneChat and click Reload config.
  </div>

  <?php if ($message !== '') { ?>
    <div class="editor-msg <?php echo sc_editor_h($message_class); ?>"><?php echo sc_editor_h($message); ?></div>
  <?php } ?>
  <div id="editor-msg-client" class="editor-msg" style="display:none"></div>

  <div class="editor-native">
    <form method="post" action="editor.php" onsubmit="return sc_editor_open_native();">
      <input type="hidden" name="open_native" value="1" />
      <button type="submit" class="btn" name="open_native" value="1">Open in Notepad</button>
      <button type="button" class="btn" onclick="window.close()">Close Window</button>
    </form>
    <div class="editor-small">If Notepad does not appear, use Start &gt; Run: <code><?php echo sc_editor_h($native_cmd); ?></code></div>
    <div class="editor-small">Fallback path: <code><?php echo sc_editor_h($native_path); ?></code></div>
  </div>

  <form method="post" action="editor.php">
    <input type="hidden" name="csrf_token" value="<?php echo sc_editor_h($csrf_token); ?>" />
    <textarea name="config_content" spellcheck="false"><?php echo sc_editor_h($content); ?></textarea>
    <div class="editor-actions">
      <button type="button" class="btn" onclick="window.close()">Close Window</button>
      <button type="submit" class="btn">Save in Web Editor</button>
    </div>
  </form>
  </div>
</div>
</body>
</html>
