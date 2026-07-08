# start_service.ps1 - launch mock (9998) and main (9999) servers in background.
# Hard wall-clock cap so this script can never hang.
$ErrorActionPreference = 'Stop'
$WallCap = 30

Get-Process php -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep 1

$Project = 'D:\Coding\stoneChat'
$Php     = 'C:\Users\linzh\scoop\shims\php.exe'

# Start mock on 9998.
$mock = Start-Process -FilePath $Php `
    -ArgumentList @('-S', '127.0.0.1:9998', '-t', $Project, "$Project\Pages\router.php") `
    -WorkingDirectory $Project `
    -RedirectStandardOutput "$Project\mock-9998.out" `
    -RedirectStandardError  "$Project\mock-9998.err" `
    -WindowStyle Hidden -PassThru

# Start main on 9999.
$main = Start-Process -FilePath $Php `
    -ArgumentList @('-S', '0.0.0.0:9999', '-t', $Project, "$Project\Pages\router.php") `
    -WorkingDirectory $Project `
    -RedirectStandardOutput "$Project\server-9999.out" `
    -RedirectStandardError  "$Project\server-9999.err" `
    -WindowStyle Hidden -PassThru

Write-Host "started mock (pid=$($mock.Id)) on :9998"
Write-Host "started main (pid=$($main.Id)) on :9999"

# Probe both with a short deadline; do not exceed $WallCap seconds.
$deadline = (Get-Date).AddSeconds($WallCap)
$mainReady = $false
$mockReady = $false
while ((Get-Date) -lt $deadline) {
    try {
        $m = Invoke-WebRequest -Uri "http://127.0.0.1:9999/" -Method GET `
            -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
        if ($m.StatusCode -in 200, 302) { $mainReady = $true }
    } catch {}
    try {
        $c = Invoke-WebRequest -Uri "http://127.0.0.1:9998/Server/api/mock_llm.php" `
            -Method OPTIONS -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
        if ($c.StatusCode -in 200, 403, 405) { $mockReady = $true }
    } catch {}
    if ($mainReady -and $mockReady) { break }
    Start-Sleep -Milliseconds 200
}

if (-not $mainReady) { Write-Host "WARN: main server not ready within $WallCap s" -ForegroundColor Yellow }
if (-not $mockReady) { Write-Host "WARN: mock server not ready within $WallCap s" -ForegroundColor Yellow }

# Final status
$net = netstat -ano | Select-String ":999[0-9] " | ForEach-Object { $_.Line }
Write-Host "listening on 999*:"
$net | ForEach-Object { Write-Host "  $_" }

# Smoke: hit the config endpoint to prove the wire works.
try {
    $cfg = Invoke-WebRequest -Uri "http://127.0.0.1:9999/Server/api/config.php" `
        -Method GET -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
    Write-Host ""
    Write-Host "GET /Server/api/config.php -> $($cfg.StatusCode)"
    $cfgObj = $cfg.Content | ConvertFrom-Json
    Write-Host "  title: $($cfgObj.title)"
    Write-Host "  providers: $($cfgObj.providers.Count) entry(ies)"
} catch {
    Write-Host "WARN: GET /api/config.php failed: $_" -ForegroundColor Yellow
}
