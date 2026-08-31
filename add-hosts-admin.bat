@echo off
:: This script must run as Administrator
net session >nul 2>&1
if errorlevel 1 (
    echo Requesting Administrator privileges...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
    exit /b
)

set HOSTS=C:\Windows\System32\drivers\etc\hosts
set ENTRY=127.0.0.1       gamepek.test

:: Check if already exists
findstr /C:"gamepek.test" "%HOSTS%" >nul 2>&1
if not errorlevel 1 (
    echo gamepek.test already in hosts file.
    pause
    exit /b
)

echo %ENTRY%>> "%HOSTS%"
echo.
echo Done! Added: %ENTRY%
echo.
echo Now restart Apache in XAMPP Control Panel,
echo then open: http://gamepek.test
echo.
pause
