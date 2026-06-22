@echo off
:: ============================================================
:: stoneChat Uninstaller (UNINSTALL.cmd)
:: Compatible with Windows XP / Vista / 7 / 10 / 11
:: No PowerShell. Uses Windows Script Host for path detection
:: and shortcut removal.
:: ============================================================

setlocal DisableDelayedExpansion



:: ------------------------------------------------------------
:: Capture script directory BEFORE any cd call.
:: ------------------------------------------------------------
set "SCRIPT_DIR=%~dp0"
if "%SCRIPT_DIR:~-1%"=="\" set "SCRIPT_DIR=%SCRIPT_DIR:~0,-1%"

:: Guard: script path must not contain !
echo("%SCRIPT_DIR%" | find "!" >nul
if not errorlevel 1 (
    echo.
    echo [FAIL] Script path contains an exclamation mark ^(!^).
    echo        Move stoneChat to a path without ! and try again.
    echo.
    pause
    endlocal
    exit /b 1
)

echo.
echo ============================================================
echo   stoneChat Uninstaller
echo ============================================================
echo.

:: ------------------------------------------------------------
:: Detect install path -- two-level priority:
::
::   Priority 1: WorkingDirectory of Desktop\stoneChat.lnk
::     INSTALL.cmd sets WorkingDirectory = install path when
::     creating the shortcut. Reading it here lets UNINSTALL.cmd
::     find the real install folder even when run from a
::     source/download copy that is not the installed location.
::
::   Priority 2: %SCRIPT_DIR% (where this script lives).
::     Used when no Desktop shortcut exists (e.g. INSTALL.cmd was
::     never run, or the shortcut was already deleted).
:: ------------------------------------------------------------
set "REMOVE_PATH="
set "REMOVE_SOURCE="

:: Read shortcut WorkingDirectory via a temporary VBS.
set "VBS_READ=%TEMP%\stonechat_readlnk_%RANDOM%.vbs"
>  "%VBS_READ%" echo Set ws  = CreateObject("WScript.Shell")
>> "%VBS_READ%" echo Set fso = CreateObject("Scripting.FileSystemObject")
>> "%VBS_READ%" echo lnk = ws.SpecialFolders("Desktop") ^& "\stoneChat.lnk"
>> "%VBS_READ%" echo If fso.FileExists(lnk) Then
>> "%VBS_READ%" echo     Set sc = ws.CreateShortcut(lnk)
>> "%VBS_READ%" echo     WScript.Echo sc.WorkingDirectory
>> "%VBS_READ%" echo Else
>> "%VBS_READ%" echo     WScript.Echo ""
>> "%VBS_READ%" echo End If
for /f "delims=" %%p in ('cscript //nologo "%VBS_READ%" 2^>nul') do set "SHORTCUT_PATH=%%p"
del "%VBS_READ%" >nul 2>&1

:: Use shortcut path if it resolves to an existing folder.
if not "%SHORTCUT_PATH%"=="" (
    if exist "%SHORTCUT_PATH%\" (
        set "REMOVE_PATH=%SHORTCUT_PATH%"
        set "REMOVE_SOURCE=desktop shortcut"
    )
)

:: Fall back to script location if shortcut not found / invalid.
if "%REMOVE_PATH%"=="" (
    set "REMOVE_PATH=%SCRIPT_DIR%"
    set "REMOVE_SOURCE=script location (no shortcut found)"
)

echo   Detected install path ^(%REMOVE_SOURCE%^):
echo     %REMOVE_PATH%
echo.

:: Guard: remove path must not contain !
echo("%REMOVE_PATH%" | find "!" >nul
if not errorlevel 1 (
    echo [FAIL] Install path contains an exclamation mark ^(!^).
    echo        Cannot remove safely. Delete the folder manually:
    echo          %REMOVE_PATH%
    echo.
    pause
    endlocal
    exit /b 1
)

echo   The following will be deleted:
echo     - All files in the folder above
echo     - Desktop shortcut "stoneChat"
echo     - Start Menu shortcut "stoneChat"
echo.
echo   Type YES and press Enter to confirm.
echo   Press Enter without typing to cancel.
echo.
set /p "CONFIRM=Confirm: "
if /i not "%CONFIRM%"=="YES" (
    echo.
    echo   Cancelled. Nothing was removed.
    echo.
    pause
    endlocal
    exit /b 0
)

echo.
echo ============================================================
echo   Removing stoneChat
echo ============================================================

:: ------------------------------------------------------------
:: 1. Stop running PHP server (if any)
:: ------------------------------------------------------------
echo.
echo [ 1/3] Stopping PHP server (if running)...
taskkill /F /IM php.exe /T >nul 2>&1
echo        Done.

:: ------------------------------------------------------------
:: 2. Remove Desktop and Start Menu shortcuts via WSH
:: ------------------------------------------------------------
echo.
echo [ 2/3] Removing shortcuts...
set "VBS_FILE=%TEMP%\stonechat_uninstall_%RANDOM%.vbs"

>  "%VBS_FILE%" echo Set ws  = CreateObject("WScript.Shell")
>> "%VBS_FILE%" echo Set fso = CreateObject("Scripting.FileSystemObject")
>> "%VBS_FILE%" echo desktop  = ws.SpecialFolders("Desktop")
>> "%VBS_FILE%" echo programs = ws.SpecialFolders("Programs")
>> "%VBS_FILE%" echo lnk1 = desktop ^& "\stoneChat.lnk"
>> "%VBS_FILE%" echo lnk2 = programs ^& "\stoneChat\stoneChat.lnk"
>> "%VBS_FILE%" echo If fso.FileExists(lnk1) Then fso.DeleteFile lnk1
>> "%VBS_FILE%" echo If fso.FileExists(lnk2) Then fso.DeleteFile lnk2

cscript //nologo "%VBS_FILE%"
if errorlevel 1 (
    echo        [WARN] Could not remove shortcuts automatically.
    echo               Delete Desktop\stoneChat.lnk manually if it remains.
) else (
    echo        Shortcuts removed.
)
del "%VBS_FILE%" >nul 2>&1

:: ------------------------------------------------------------
:: 3. Remove install directory.
:: Move CWD to %SystemRoot% first -- Windows cannot rd /s the
:: current working directory.
:: ------------------------------------------------------------
echo.
echo [ 3/3] Removing install folder...
echo        %REMOVE_PATH%

if not exist "%REMOVE_PATH%\" (
    echo        [WARN] Folder not found: %REMOVE_PATH%
    goto :done
)

cd /d "%SystemRoot%"
rd /s /q "%REMOVE_PATH%" >nul 2>&1
if exist "%REMOVE_PATH%\" (
    echo        [WARN] Folder could not be fully removed.
    echo               Close all stoneChat windows, then delete manually:
    echo                 %REMOVE_PATH%
) else (
    echo        Folder removed.
)

:done
echo.
echo ============================================================
echo   stoneChat has been uninstalled.
echo ============================================================
echo.
pause
endlocal
exit /b 0
