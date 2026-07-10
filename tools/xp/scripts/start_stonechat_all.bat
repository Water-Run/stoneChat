@echo off
setlocal

rem XP launches the repository directly from the VMware application share.
set APP_ROOT=\\vmware-host\Shared Folders\stoneChat
set PHP_BIN=C:\PHP54\php.exe
set RUNTIME_DIR=C:\stoneChat-runtime
set PROBE=%~dp0probe_stonechat_http.php

if not exist "%APP_ROOT%\Pages\router.php" (
    echo [FATAL] stoneChat router is unavailable at "%APP_ROOT%".
    exit /b 1
)
if not exist "%APP_ROOT%\Server\api\mock_llm.php" (
    echo [FATAL] stoneChat mock endpoint is unavailable at "%APP_ROOT%".
    exit /b 1
)
if not exist "%PHP_BIN%" (
    echo [FATAL] PHP 5.4 was not found at "%PHP_BIN%".
    exit /b 1
)
if not exist "%PROBE%" (
    echo [FATAL] HTTP probe is unavailable at "%PROBE%".
    exit /b 1
)
if not exist "%RUNTIME_DIR%" md "%RUNTIME_DIR%"
if not exist "%RUNTIME_DIR%" (
    echo [FATAL] Could not create "%RUNTIME_DIR%".
    exit /b 1
)

call :stop_port 9999
call :stop_port 9998

start "stoneChat main" /MIN cmd /c "pushd ""%APP_ROOT%"" && ""%PHP_BIN%"" -S 127.0.0.1:9999 Pages\router.php > ""C:\stoneChat-runtime\main.log"" 2>&1"
if errorlevel 1 goto start_failed

start "stoneChat mock" /MIN cmd /c "pushd ""%APP_ROOT%"" && ""%PHP_BIN%"" -S 127.0.0.1:9998 Server\api\mock_llm.php > ""C:\stoneChat-runtime\mock.log"" 2>&1"
if errorlevel 1 goto start_failed

for /l %%i in (1,1,5) do (
    "%PHP_BIN%" "%PROBE%" "http://127.0.0.1:9999/" >nul 2>&1 && goto probe_ok
    ping -n 2 127.0.0.1 >nul
)

echo [FATAL] stoneChat did not answer HTTP. See "%RUNTIME_DIR%\main.log".
exit /b 1

:probe_ok
echo stoneChat services are ready. Logs: "%RUNTIME_DIR%\main.log" and "%RUNTIME_DIR%\mock.log".
exit /b 0

:start_failed
echo [FATAL] Could not start stoneChat. See "%RUNTIME_DIR%\main.log" and "%RUNTIME_DIR%\mock.log".
exit /b 1

:stop_port
for /f "tokens=5" %%a in ('netstat -ano ^| find ":%1" ^| find "LISTENING"') do taskkill /F /PID %%a >nul 2>&1
exit /b 0
