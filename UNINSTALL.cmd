@echo off
:: ============================================================
:: stoneChat Uninstaller (UNINSTALL.cmd)
:: Compatible with Windows XP / Vista / 7 / 10 / 11
:: No PowerShell. Uses Windows Script Host for shortcut removal.
:: ============================================================

setlocal DisableDelayedExpansion

chcp 65001 >nul

:: ------------------------------------------------------------
:: Capture script location BEFORE any cd, while %~dp0 is valid.
:: DO NOT cd into the install dir -- we need to rd /s /q it later
:: and Windows cannot delete the current working directory.
:: ------------------------------------------------------------
set "REMOVE_PATH=%~dp0"
:: Strip trailing backslash.
if "%REMOVE_PATH:~-1%"=="\" set "REMOVE_PATH=%REMOVE_PATH:~0,-1%"

:: Guard: path must not contain ! (delayed expansion safety).
echo("%REMOVE_PATH%" | find "!" >nul
if not errorlevel 1 (
    echo.
    echo [FAIL] Install path contains an exclamation mark ^(!^).
    echo        CMD cannot safely handle this path.
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
echo   This will remove stoneChat from your computer.
echo.
echo   Detected install path:
echo     %REMOVE_PATH%
echo.
echo   The following will be deleted:
echo     - All files in the folder above
echo     - Desktop shortcut "stoneChat"
echo     - Start Menu shortcut "stoneChat"
echo.
echo   Type YES (uppercase) and press Enter to confirm.
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
:: 3. Remove install directory
:: Move CWD away first -- Windows cannot rd the current directory.
:: ------------------------------------------------------------
echo.
echo [ 3/3] Removing install folder...
echo        %REMOVE_PATH%

if not exist "%REMOVE_PATH%\" (
    echo        [WARN] Folder not found: %REMOVE_PATH%
    goto :done
)

:: Move CWD to Windows dir so the install folder is not "in use".
cd /d "%SystemRoot%"

rd /s /q "%REMOVE_PATH%" >nul 2>&1
if exist "%REMOVE_PATH%\" (
    echo        [WARN] Folder could not be fully removed.
    echo               Some files may still be open. Close all stoneChat
    echo               windows and delete the folder manually:
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
