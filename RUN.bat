@echo off
:: ============================================================
:: stoneChat launcher (RUN.bat)
:: Pure-CMD, no PowerShell / no wmic dependencies.
:: Verifies the runtime environment before starting PHP.
:: ============================================================

setlocal DisableDelayedExpansion


cd /d "%~dp0"

set "SC_SOURCE_PATH=%CD%"
set "SC_TEST_PATH=%SC_SOURCE_PATH:!=%"
if not "%SC_SOURCE_PATH%"=="%SC_TEST_PATH%" (
    echo.
    echo [FAIL] Current stoneChat path contains an exclamation mark !.
    echo        CMD delayed expansion cannot safely run from this path.
    echo        Move stoneChat to a path without ! and run RUN.bat again.
    echo.
    pause
    endlocal
    exit /b 1
)

set "STUNNEL_PATH=C:\Program Files\stunnel\bin\stunnel.exe"
if exist "%~dp0CONF.ini" (
    for /f "usebackq eol=; tokens=1,* delims==" %%a in ("%~dp0CONF.ini") do (
        for /f "tokens=* delims= " %%k in ("%%a") do (
            if /i "%%k"=="stunnel" (
                for /f "tokens=* delims= " %%s in ("%%b") do set "STUNNEL_PATH=%%s"
            )
        )
    )
)
set "STUNNEL_TEST_PATH=%STUNNEL_PATH:!=%"
if not "%STUNNEL_PATH%"=="%STUNNEL_TEST_PATH%" (
    echo.
    echo [FAIL] Stunnel path contains an exclamation mark !.
    echo        CMD delayed expansion cannot safely use that path.
    echo        Install stunnel in a folder without !, or edit CONF.ini.
    echo.
    pause
    endlocal
    exit /b 1
)

setlocal EnableDelayedExpansion

echo.
echo ============================================================
echo   stoneChat launcher
echo ============================================================
echo.

set "ERR_COUNT=0"
set "PHP_OK=0"
set "PORT_OK=0"
set "CONF_OK=0"
set "STUNNEL_OK=0"
set "CACERT_OK=0"
set "HISTORY_OK=0"
set "WIN_BUILD=0"
set "SC_MODERN=0"

:: ------------------------------------------------------------
:: 1. PHP 5.4 or later in PATH
:: ------------------------------------------------------------
echo [ 1/7] PHP 5.4 or later in PATH...
php -v >nul 2>&1
if errorlevel 1 (
    echo        [FAIL] PHP not found in PATH.
    echo               Install PHP 5.4+ and add php.exe to PATH.
    echo               ^(5.4+ is required for the built-in web server "php -S"^)
    echo               Windows XP : php-5.4.45-Win32-VC9-x86.zip from archives
    echo               Download   : https://windows.php.net/downloads/releases/archives/
    set /a "ERR_COUNT+=1"
) else (
    for /f "delims=" %%v in ('php -r "echo PHP_VERSION;" 2^>nul') do set "PHPVER=%%v"
    php -r "exit(version_compare(PHP_VERSION, '5.4.0', '>=') ? 0 : 1);" >nul 2>&1
    if errorlevel 1 (
        echo        [FAIL] PHP version too old. Found !PHPVER!, need 5.4 or later.
        echo               Windows XP : php-5.4.45-Win32-VC9-x86.zip ^(last XP-compatible release^)
        echo               Download   : https://windows.php.net/downloads/releases/archives/
        set /a "ERR_COUNT+=1"
    ) else (
        echo        [ OK ] PHP !PHPVER! found.
        set "PHP_OK=1"
    )
)

:: ------------------------------------------------------------
:: 2. CONF.ini present and validate_config-clean
:: ------------------------------------------------------------
echo [ 2/7] CONF.ini present and valid...
if not exist "%~dp0CONF.ini" (
    if exist "%~dp0CONF_SMP.INI" (
        copy /Y "%~dp0CONF_SMP.INI" "%~dp0CONF.ini" >nul
        echo        [INFO] Created CONF.ini from CONF_SMP.INI template.
    )
)

if not exist "%~dp0CONF.ini" (
    echo        [FAIL] CONF.ini not found, and no CONF_SMP.INI template available.
    echo               Please restore CONF_SMP.INI or create CONF.ini.
    set /a "ERR_COUNT+=1"
) else (
    if "!PHP_OK!"=="1" (
        for /f "delims=" %%e in ('php -r "require 'Server/config.php';$c=sc_load_config('CONF.ini');$e=sc_config_fatal_errors(sc_validate_config($c));echo empty($e)?'OK':'ERR:'.implode(',',$e);" 2^>nul') do set "VALIDATE_OUT=%%e"
        if "!VALIDATE_OUT!"=="OK" (
            echo        [ OK ] CONF.ini present and valid.
            set "CONF_OK=1"
        ) else (
            echo        [FAIL] CONF.ini validation failed: !VALIDATE_OUT!
            echo               Edit CONF.ini ^(server port and auth password are required^).
            set /a "ERR_COUNT+=1"
        )
    ) else (
        echo        [SKIP] Skipped ^(PHP not available -- fix item 1 first^).
    )
)

:: ------------------------------------------------------------
:: 3. Port 9999 (or [server] port) not already bound
:: ------------------------------------------------------------
echo [ 3/7] Listening port availability...
set "SC_PORT=9999"
if "!CONF_OK!"=="1" (
    for /f "delims=" %%p in ('php -r "require 'Server/config.php';$c=sc_load_config('CONF.ini');echo isset($c['server']['port'])?(int)$c['server']['port']:9999;" 2^>nul') do set "SC_PORT=%%p"
    if "!SC_PORT!"=="" set "SC_PORT=9999"
    for /f "delims=" %%s in ('php -r "require 'Server/config.php';$c=sc_load_config('CONF.ini');echo isset($c['paths']['stunnel'])?$c['paths']['stunnel']:'';" 2^>nul') do set "STUNNEL_PATH=%%s"
    if "!STUNNEL_PATH!"=="" set "STUNNEL_PATH=C:\Program Files\stunnel\bin\stunnel.exe"
    if not exist "!STUNNEL_PATH!" (
        if exist "C:\Program Files (x86)\stunnel\bin\stunnel.exe" (
            set "STUNNEL_PATH=C:\Program Files (x86)\stunnel\bin\stunnel.exe"
        )
    )
)
netstat -ano | findstr /R /C:"LISTENING" | findstr /C:":!SC_PORT! " >nul 2>&1
if not errorlevel 1 (
    echo        [FAIL] Port !SC_PORT! is already in use by another LISTENING process.
    echo               Stop the conflicting process or change [server] port in CONF.ini.
    set /a "ERR_COUNT+=1"
) else (
    echo        [ OK ] Port !SC_PORT! is free.
    set "PORT_OK=1"
)

:: ------------------------------------------------------------
:: 4. [paths] stunnel executable exists
:: ------------------------------------------------------------
echo [ 4/7] stunnel executable...
if exist "!STUNNEL_PATH!" (
    echo        [ OK ] stunnel found: "!STUNNEL_PATH!"
    set "STUNNEL_OK=1"
) else (
    echo        [FAIL] stunnel.exe not found at: "!STUNNEL_PATH!"
    echo               Install stunnel, or edit CONF.ini [paths] stunnel.
    echo               Download: https://www.stunnel.org/downloads.html
    set /a "ERR_COUNT+=1"
)

:: ------------------------------------------------------------
:: 5. ModernNetwork\cacert.pem present
:: ------------------------------------------------------------
echo [ 5/7] CA certificate ^(cacert.pem^)...
if exist "%~dp0ModernNetwork\cacert.pem" (
    echo        [ OK ] cacert.pem present.
    set "CACERT_OK=1"
) else (
    echo        [FAIL] ModernNetwork\cacert.pem not found.
    echo               Run from the stoneChat project root, or restore cacert.pem.
    set /a "ERR_COUNT+=1"
)

:: ------------------------------------------------------------
:: 6. HISTORY/ creatable + writable
:: ------------------------------------------------------------
echo [ 6/7] HISTORY directory...
if not exist "%~dp0HISTORY\" (
    mkdir "%~dp0HISTORY" >nul 2>&1
)
if exist "%~dp0HISTORY\" (
    set "HISTORY_TMP=%~dp0HISTORY\.sc_write_test.tmp"
    echo. > "!HISTORY_TMP!" 2>nul
    if exist "!HISTORY_TMP!" (
        del "!HISTORY_TMP!" >nul 2>&1
        echo        [ OK ] HISTORY\ exists and is writable.
        set "HISTORY_OK=1"
    ) else (
        echo        [FAIL] HISTORY\ is not writable.
        set /a "ERR_COUNT+=1"
    )
) else (
    echo        [FAIL] Cannot create HISTORY\ at project root.
    set /a "ERR_COUNT+=1"
)

:: ------------------------------------------------------------
:: 7. Windows version detection + modern-Windows note
:: ------------------------------------------------------------
echo [ 7/7] Windows version detection...
set "VER_STR="
for /f "tokens=*" %%v in ('ver') do set "VER_STR=%%v"
set "VER_INNER="
for /f "tokens=2 delims=[]" %%a in ("!VER_STR!") do set "VER_INNER=%%a"
set "VER_NUM="
for /f "tokens=2"        %%a in ("!VER_INNER!") do set "VER_NUM=%%a"
for /f "tokens=3 delims=." %%a in ("!VER_NUM!") do set "WIN_BUILD=%%a"
if "!WIN_BUILD!"=="" set "WIN_BUILD=0"
set "WIN_BUILD=!WIN_BUILD: =!"
echo(!WIN_BUILD!| findstr /R "^[0-9][0-9]*$" >nul
if errorlevel 1 set "WIN_BUILD=0"
if !WIN_BUILD! GEQ 17763 (
    set "SC_MODERN=1"
    echo        [INFO] Windows build !WIN_BUILD! detected ^(Windows 10 1809 or newer^).
    echo               Your device looks very modern; many modern tools are available.
    echo               stoneChat is a retro LLM client ^(IE6 / Windows XP era^).
    echo               It still runs fine; the UI just looks 20+ years old by design.
    echo               Expect a brief "Super-Modern-HTML" splash on first page load.
) else (
    echo        [ OK ] Windows build !WIN_BUILD! ^< 17763; the retro UI will load directly.
)

echo.
if !ERR_COUNT! GTR 0 (
    echo ============================================================
    echo   stoneChat startup check FAILED: !ERR_COUNT! error^(s^).
    echo   Please fix the issues above and re-run RUN.bat.
    echo ============================================================
    echo.
    echo   Suggested order of steps:
    if "!PHP_OK!"=="0" (
        echo   [Step A] Install PHP 5.4+ and add php.exe to PATH.
        echo           ^(5.4+ is required for the built-in web server "php -S"^)
        echo           Windows 10/11 : https://windows.php.net/download/
        echo           Windows XP    : https://windows.php.net/downloads/releases/archives/
        echo           ^(pick the latest php-5.4.x-Win32-VC9 package for XP^)
    )
    if "!STUNNEL_OK!"=="0" (
        echo   [Step B] Install stunnel and set [paths] stunnel in CONF.ini.
        echo           Download: https://www.stunnel.org/downloads.html
    )
    if "!CONF_OK!"=="0" if "!PHP_OK!"=="1" (
        echo   [Step C] Edit CONF.ini: set a real password and API keys.
    )
    echo.
    echo   After fixing, re-run RUN.bat.
    echo.
    pause
    endlocal
    endlocal
    exit /b 1
)

echo Environment check passed. Launching stoneChat...
echo.

:: ------------------------------------------------------------
:: Launch
:: ------------------------------------------------------------
if "!SC_MODERN!"=="1" (
    echo Opening browser to http://localhost:!SC_PORT!/ ^(-- modern splash first --^)
) else (
    echo Opening browser to http://localhost:!SC_PORT!/
)
start "" "http://localhost:!SC_PORT!/"
php -S localhost:!SC_PORT! Pages/router.php

echo.
echo stoneChat has stopped.
pause
endlocal
endlocal
exit /b 0
