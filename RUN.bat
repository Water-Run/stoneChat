@echo off
chcp 65001 >nul
cd /d %~dp0
echo Starting stoneChat on http://localhost:9999/
echo Press Ctrl+C to stop.
start "" "http://localhost:9999/"
php -S localhost:9999 Pages/router.php
pause
