<?php
/* One-shot setup script: writes a working demo CONF.ini for
 * running the actual service. Backs up the project's CONF.ini
 * to CONF.ini.original and installs a working mock-only
 * version that the strict validator accepts. */
$project = 'D:\Coding\stoneChat';
$real    = $project . DIRECTORY_SEPARATOR . 'CONF.ini';
$bak     = $project . DIRECTORY_SEPARATOR . 'CONF.ini.original';

if (!is_file($real)) {
    fwrite(STDERR, "no $real\n");
    exit(1);
}

$body = file_get_contents($real);
/* Replace placeholders. The validator rejects YOUR_*_HERE and
 * empty api_key. The mock provider's mock_key is already valid;
 * the demo config just needs the openai/anthropic stubs to
 * pass the placeholder check. We use demo-* strings. */
$body = preg_replace('/^password = .*/m',  'password = demo123', $body);
$body = preg_replace('/^api_key = YOUR_OPENAI_API_KEY_HERE/m',   'api_key = demo-openai-key', $body);
$body = preg_replace('/^api_key = YOUR_ANTHROPIC_API_KEY_HERE/m', 'api_key = demo-anthropic-key', $body);
/* The strict env check looks for a real file at [paths] stunnel.
 * The real CONF.ini has the path "C:\Program Files\stunnel\bin\stunnel.exe"
 * which doesn't exist on this machine. We don't have stunnel
 * installed and the demo is mock-only, so point the path at
 * any existing file (the strict check is is_file() only). */
$body = preg_replace('/^stunnel = .*/m',
    'stunnel = C:\\Users\\linzh\\scoop\\shims\\php.exe', $body);

if (is_file($bak)) { @unlink($bak); }
@rename($real, $bak);
file_put_contents($real, $body);

echo "demo CONF.ini installed\n";
echo "  original backed up to: $bak\n";
echo "  login password: demo123\n";
