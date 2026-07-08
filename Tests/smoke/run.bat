@echo off
:: ============================================================
:: stoneChat smoke test runner (Windows)
:: ============================================================
:: Wraps tests/smoke/run.php with a hard wall-clock cap. If the
:: child PHP hangs for any reason, this script kills the tree
:: after SC_WALL_TIMEOUT seconds (default 150). Pass any extra
:: args through to run.php.
:: ============================================================
setlocal
chcp 65001 >nul
cd /d "%~dp0\..\.."

where php >nul 2>&1
if errorlevel 1 (
    echo [FATAL] php.exe not found on PATH. Install PHP and retry.
    exit /b 2
)

set "SC_WALL_TIMEOUT=150"
if not "%SC_WALL_TIMEOUT%"=="" set "SC_WALL_TIMEOUT=%SC_WALL_TIMEOUT%"

:: Launch the test in a backgrounded cmd so we can apply a
:: watchdog. We use ping as a poor man's timer.
start /b "" php "%~dp0run.php" %*
set "PID=%ERRORLEVEL%"

:: Watchdog loop. ping -n waits ~1s per count; use 150 ticks
:: to approximate 150s. Poll proc_open style would be nicer
:: but cmd has nothing; this works on every Windows.
ping -n %SC_WALL_TIMEOUT% 127.0.0.1 >nul

:: After the wall-time, check whether the php -S tree still
:: owns the port. If it does, kill the whole tree.
set "STILL_UP=0"
netstat -ano | findstr "LISTENING" | findstr ":19999 " >nul 2>&1
if not errorlevel 1 set "STILL_UP=1"

if "%STILL_UP%"=="1" (
    echo.
    echo [WATCHDOG] test runner exceeded %SC_WALL_TIMEOUT%s wall clock; killing PHP tree.
    for /f "tokens=5" %%P in ('netstat -ano ^| findstr "LISTENING" ^| findstr ":19999 "') do (
        taskkill /F /T /PID %%P >nul 2>&1
    )
    taskkill /F /IM php.exe /T >nul 2>&1
    exit /b 124
)

:: Otherwise the test finished. The php child should already
:: have exited, but make sure no stragglers remain.
taskkill /F /IM php.exe /T >nul 2>&1
exit /b 0
