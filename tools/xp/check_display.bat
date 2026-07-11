@echo off
dir /b "C:\Program Files\VMware\VMware Tools"
echo ---DPI---
reg query "HKCU\Control Panel\Desktop" /v LogPixels
reg query "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\FontDPI" /v LogPixels
reg query "HKLM\SYSTEM\CurrentControlSet\Hardware Profiles\Current\Software\Fonts" /v LogPixels
echo ---RES---
wmic path Win32_VideoController get CurrentHorizontalResolution,CurrentVerticalResolution /value
