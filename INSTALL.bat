@echo off
:: ============================================================
:: stoneChat Installer
:: Compatible with Windows XP / Vista / 7 / 10 / 11
:: Requires: PHP 5.2+ in PATH, Windows Script Host.
:: Optional: PowerShell (used for desktop / Start Menu shortcuts).
:: ============================================================

setlocal enabledelayedexpansion

chcp 65001 >nul
cd /d "%~dp0"

echo.
echo ============================================================
echo   stoneChat Installer
echo ============================================================
echo.
echo This installer will:
echo   1. Check your environment (PHP, ports, files, disk, registry,
echo      Windows version).
echo   2. Copy stoneChat files to the chosen folder.
echo   3. Create a HISTORY\ directory for chat logs.
echo   4. Create Desktop and Start Menu shortcuts.
echo.

:: ------------------------------------------------------------
:: Windows version detection (early, for "modern" warning)
:: ------------------------------------------------------------
set "WIN_BUILD=0"
set "SC_MODERN=0"
for /f "tokens=*" %%v in ('ver') do set "VER_STR=%%v"
for /f "tokens=2 delims=[]" %%a in ("!VER_STR!") do set "VER_INNER=%%a"
for /f "tokens=2"        %%a in ("!VER_INNER!") do set "VER_NUM=%%a"
for /f "tokens=3 delims=." %%a in ("!VER_NUM!") do set "WIN_BUILD=%%a"
if "!WIN_BUILD!"=="" set "WIN_BUILD=0"
if !WIN_BUILD! GEQ 17763 set "SC_MODERN=1"

:: ------------------------------------------------------------
:: Prompt for install path
:: ------------------------------------------------------------
set "DEFAULT_INSTALL_PATH=C:\Program Files\stoneChat"
set "INSTALL_PATH="

set /p "INSTALL_PATH=Install path [%DEFAULT_INSTALL_PATH%]: "
if "%INSTALL_PATH%"=="" set "INSTALL_PATH=%DEFAULT_INSTALL_PATH%"

:: Strip a single trailing backslash for consistent quoting later.
if "%INSTALL_PATH:~-1%"=="\" set "INSTALL_PATH=%INSTALL_PATH:~0,-1%"

echo.
echo Installing to: "%INSTALL_PATH%"
echo.

:: ============================================================
:: Environment Check (8 checks)
:: ============================================================
set "ERR_COUNT=0"

echo ============================================================
echo  Environment Check
echo ============================================================

:: ---- 1. PHP 5.2 or later ----
echo [ 1/8] PHP 5.2 or later in PATH...
php -v >nul 2>&1
if errorlevel 1 (
    echo        [FAIL] PHP not found in PATH.
    echo               Please install PHP 5.2 or later and add php.exe to PATH.
    set /a "ERR_COUNT+=1"
) else (
    for /f "delims=" %%v in ('php -r "echo PHP_VERSION;" 2^>nul') do set "PHPVER=%%v"
    php -r "exit(version_compare(PHP_VERSION, '5.2.0', '>=') ? 0 : 1);" >nul 2>&1
    if errorlevel 1 (
        echo        [FAIL] PHP version too old. Found !PHPVER!, need 5.2 or later.
        set /a "ERR_COUNT+=1"
    ) else (
        echo        [ OK ] PHP !PHPVER! found.
    )
)

:: ---- 2. Port 9999 free ----
echo [ 2/8] Port 9999 availability...
netstat -an | findstr /R /C:":9999 " >nul 2>&1
if not errorlevel 1 (
    echo        [WARN] Port 9999 appears in use. The installer will continue,
    echo               but you may need to free the port (or change [server]
    echo               port in CONF.ini) before starting the server.
) else (
    echo        [ OK ] Port 9999 is free.
)

:: ---- 3. stunnel.exe present (path from CONF.ini [paths] stunnel) ----
echo [ 3/8] stunnel executable...
set "STUNNEL_PATH=C:\Program Files\stunnel\bin\stunnel.exe"
if exist "%~dp0CONF.ini" (
    for /f "usebackq eol=; tokens=1,* delims==" %%a in ("%~dp0CONF.ini") do (
        set "_key=%%a"
        set "_val=%%b"
        set "_key=!_key: =!"
        if /i "!_key!"=="stunnel" (
            for /f "tokens=*" %%v in ("!_val!") do set "STUNNEL_PATH=%%v"
        )
    )
)
if exist "%STUNNEL_PATH%" (
    echo        [ OK ] stunnel found: %STUNNEL_PATH%
) else (
    echo        [WARN] stunnel.exe not found at: %STUNNEL_PATH%
    echo               ModernNetwork/HTTPS features will be disabled until
    echo               stunnel is installed. Edit CONF.ini [paths] stunnel
    echo               after install if you need HTTPS for remote LLM APIs.
)

:: ---- 4. cacert.pem present ----
echo [ 4/8] CA certificate (cacert.pem)...
if exist "%~dp0ModernNetwork\cacert.pem" (
    echo        [ OK ] cacert.pem present.
) else (
    echo        [FAIL] ModernNetwork\cacert.pem not found.
    echo               Run INSTALL.bat from the stoneChat project root.
    set /a "ERR_COUNT+=1"
)

:: ---- 5. Disk space >= 100 MB on target drive ----
echo [ 5/8] Disk space on install drive...
set "TARGET_DRIVE=%INSTALL_PATH:~0,2%"
set "FREEBYTES=0"
for /f "tokens=2 delims=:" %%b in ('fsutil volume diskfree %TARGET_DRIVE% 2^>nul ^| findstr /c:"free bytes"') do (
    set "FREEBYTES=%%b"
)
set "FREEBYTES=%FREEBYTES: =%"
if "%FREEBYTES%"=="" set "FREEBYTES=0"
if %FREEBYTES% LSS 104857600 (
    echo        [FAIL] Less than 100 MB free on %TARGET_DRIVE%.
    echo               Need at least 100 MB; found %FREEBYTES% bytes.
    set /a "ERR_COUNT+=1"
) else (
    set /a "FREEMB=%FREEBYTES% / 1048576"
    echo        [ OK ] %FREEMB% MB free on %TARGET_DRIVE%.
)

:: ---- 6. HISTORY directory creatable at install path ----
echo [ 6/8] HISTORY directory...
if not exist "%INSTALL_PATH%" mkdir "%INSTALL_PATH%" >nul 2>&1
mkdir "%INSTALL_PATH%\HISTORY" >nul 2>&1
if exist "%INSTALL_PATH%\HISTORY\" (
    rmdir "%INSTALL_PATH%\HISTORY" >nul 2>&1
    echo        [ OK ] HISTORY\ can be created at the install path.
) else (
    echo        [FAIL] Cannot create HISTORY\ at "%INSTALL_PATH%".
    echo               Check folder permissions or pick a different path.
    set /a "ERR_COUNT+=1"
)

:: ---- 7. Install path writable ----
echo [ 7/8] Install path write permission...
set "TEST_FILE=%INSTALL_PATH%\stonechat_write_test.tmp"
echo. > "%TEST_FILE%" 2>nul
if exist "%TEST_FILE%" (
    del "%TEST_FILE%" >nul 2>&1
    echo        [ OK ] Install path is writable.
) else (
    echo        [FAIL] Cannot write to "%INSTALL_PATH%".
    echo               Run as Administrator or pick a writable path.
    set /a "ERR_COUNT+=1"
)

:: ---- 8. Registry writable (needed for shortcuts) ----
echo [ 8/8] Registry write permission...
reg add "HKCU\Software\stoneChat_test" /v test /t REG_SZ /d test /f >nul 2>&1
if not errorlevel 1 (
    reg delete "HKCU\Software\stoneChat_test" /f >nul 2>&1
    echo        [ OK ] Registry writable.
) else (
    echo        [FAIL] Cannot write to the registry.
    echo               Desktop and Start Menu shortcuts need registry access.
    set /a "ERR_COUNT+=1"
)

:: ------------------------------------------------------------
:: Windows-version note (informational, never fatal)
:: ------------------------------------------------------------
echo.
if "!SC_MODERN!"=="1" (
    echo ============================================================
    echo  Windows version notice
    echo ============================================================
    echo  Detected Windows build !WIN_BUILD! (Windows 10 1809 or newer).
    echo  Your device looks very modern; many modern tools are available.
    echo.
    echo  stoneChat is a retro LLM client (IE6 / Windows XP era). It still
    echo  runs fine on modern hardware; the UI just looks 20+ years old by
    echo  design. Expect a brief "Super-Modern-HTML" splash on the first
    echo  page load of every browser session; it auto-redirects after 3s.
    echo ============================================================
    echo.
) else (
    echo [ OK ] Windows build !WIN_BUILD! ^< 17763; the retro UI will load
    echo        directly with no splash interlude.
    echo.
)

if !ERR_COUNT! GTR 0 (
    echo ============================================================
    echo  Environment check FAILED: !ERR_COUNT! error(s) found.
    echo  Please fix the above issues and re-run INSTALL.bat.
    echo ============================================================
    echo.
    pause
    endlocal
    exit /b 1
)
echo Environment check passed.
echo.

:: ============================================================
:: Copy files to install path
:: ============================================================
echo ============================================================
echo  Copying files
echo ============================================================
if not exist "%INSTALL_PATH%" mkdir "%INSTALL_PATH%" >nul 2>&1

xcopy "%~dp0Pages" "%INSTALL_PATH%\Pages\" /E /I /Y /Q >nul
if errorlevel 1 goto :copy_failed

xcopy "%~dp0Server" "%INSTALL_PATH%\Server\" /E /I /Y /Q >nul
if errorlevel 1 goto :copy_failed

xcopy "%~dp0ModernNetwork" "%INSTALL_PATH%\ModernNetwork\" /E /I /Y /Q >nul
if errorlevel 1 goto :copy_failed

xcopy "%~dp0Assets" "%INSTALL_PATH%\Assets\" /E /I /Y /Q >nul
if errorlevel 1 goto :copy_failed

copy /Y "%~dp0RUN.bat" "%INSTALL_PATH%\" >nul
if errorlevel 1 goto :copy_failed

copy /Y "%~dp0INSTALL.bat" "%INSTALL_PATH%\" >nul
if errorlevel 1 goto :copy_failed

copy /Y "%~dp0CONF.ini" "%INSTALL_PATH%\" >nul
if errorlevel 1 goto :copy_failed

if exist "%~dp0README" copy /Y "%~dp0README" "%INSTALL_PATH%\" >nul
if exist "%~dp0LICENSE.txt" copy /Y "%~dp0LICENSE.txt" "%INSTALL_PATH%\" >nul

echo Files copied.
goto :copy_ok

:copy_failed
echo [ERROR] Failed to copy files to "%INSTALL_PATH%".
echo         Check disk space, folder permissions, and try again.
echo.
pause
endlocal
exit /b 1

:copy_ok
echo.

:: ============================================================
:: Create runtime directories (HISTORY\ and Server\langs\)
:: ============================================================
echo Creating runtime directories...
mkdir "%INSTALL_PATH%\HISTORY" >nul 2>&1
if exist "%INSTALL_PATH%\HISTORY\" (
    echo HISTORY\ ready.
) else (
    echo [WARN] Could not create HISTORY\. Some history features may fail.
)
mkdir "%INSTALL_PATH%\Server\langs" >nul 2>&1
if exist "%INSTALL_PATH%\Server\langs\" (
    echo Server\langs\ ready.
) else (
    echo [WARN] Could not create Server\langs\. Translations may fail to load.
)
echo.

:: ============================================================
:: Run Server\install.php (if present)
:: ============================================================
if exist "%INSTALL_PATH%\Server\install.php" (
    echo Running Server\install.php...
    php "%INSTALL_PATH%\Server\install.php"
    if errorlevel 1 (
        echo [ERROR] Server\install.php failed. Aborting.
        echo.
        pause
        endlocal
        exit /b 1
    )
    echo Server\install.php completed.
) else (
    echo [WARN] Server\install.php not found, skipping backend init.
)
echo.

:: ============================================================
:: Create Desktop and Start Menu shortcuts via PowerShell + WshShell COM
:: ============================================================
echo ============================================================
echo  Creating shortcuts
echo ============================================================
:: Detect PowerShell portably: 'where' is Vista+, so probe directly.
powershell -Command "exit 0" >nul 2>&1
if errorlevel 1 (
    echo [WARN] PowerShell not found. Shortcuts not created.
    echo        stoneChat is installed at: "%INSTALL_PATH%"
    echo        Use RUN.bat there to start it manually.
    goto :shortcut_done
)

:: Materialize a small PowerShell script in %TEMP%, then invoke it.
:: PowerShell here uses WshShell COM (XP-compatible WSH via PS 1.0/2.0).
set "PS_FILE=%TEMP%\stonechat_install_%RANDOM%.ps1"

>  "%PS_FILE%" echo $ErrorActionPreference = 'Stop'
>> "%PS_FILE%" echo $ws = New-Object -ComObject WScript.Shell
>> "%PS_FILE%" echo $desktop = [Environment]::GetFolderPath('Desktop')
>> "%PS_FILE%" echo $startMenu = [Environment]::GetFolderPath('StartMenu')
>> "%PS_FILE%" echo $programsDir = $startMenu ^+ '\Programs\stoneChat'
>> "%PS_FILE%" echo if ^(-not ^(Test-Path $programsDir^)^) { New-Item -ItemType Directory -Path $programsDir -Force ^| Out-Null }
>> "%PS_FILE%" echo $sc1 = $ws.CreateShortcut^($desktop ^+ '\stoneChat.lnk'^)
>> "%PS_FILE%" echo $sc1.TargetPath = '%INSTALL_PATH%\RUN.bat'
>> "%PS_FILE%" echo $sc1.WorkingDirectory = '%INSTALL_PATH%'
>> "%PS_FILE%" echo $sc1.IconLocation = '%INSTALL_PATH%\Assets\logo.png'
>> "%PS_FILE%" echo $sc1.Description = 'stoneChat - LLM Web Chat'
>> "%PS_FILE%" echo $sc1.WindowStyle = 7
>> "%PS_FILE%" echo $sc1.Save^(^)
>> "%PS_FILE%" echo $sc2 = $ws.CreateShortcut^($programsDir ^+ '\stoneChat.lnk'^)
>> "%PS_FILE%" echo $sc2.TargetPath = '%INSTALL_PATH%\RUN.bat'
>> "%PS_FILE%" echo $sc2.WorkingDirectory = '%INSTALL_PATH%'
>> "%PS_FILE%" echo $sc2.IconLocation = '%INSTALL_PATH%\Assets\logo.png'
>> "%PS_FILE%" echo $sc2.Description = 'stoneChat - LLM Web Chat'
>> "%PS_FILE%" echo $sc2.WindowStyle = 7
>> "%PS_FILE%" echo $sc2.Save^(^)
>> "%PS_FILE%" echo Write-Host 'Shortcuts created.'

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_FILE%"
if errorlevel 1 (
    echo [WARN] PowerShell reported an error. Shortcuts may be missing.
) else (
    echo Shortcuts created.
)
del "%PS_FILE%" >nul 2>&1

:shortcut_done
echo.

:: ============================================================
:: Done
:: ============================================================
echo ============================================================
echo  stoneChat installed successfully!
echo ============================================================
echo.
echo   Install path : %INSTALL_PATH%
echo   Web URL      : http://localhost:9999/
echo.
echo   Double-click the desktop icon, or run:
echo       "%INSTALL_PATH%\RUN.bat"
echo.
if "!SC_MODERN!"=="1" (
    echo   Note: your Windows build is ^>=^= 10 1809, so the first page load
    echo   of every browser session will show a brief "Super-Modern-HTML"
    echo   splash for 3 seconds before the retro UI appears. This is by
    echo   design and is safe to dismiss by waiting.
    echo.
)
echo   Edit CONF.ini at the install path to set your API keys
echo   before the first chat.
echo ============================================================
echo.
pause
endlocal
exit /b 0
