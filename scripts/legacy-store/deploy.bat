@echo off
chcp 65001 >nul
echo ====================================================
echo   GamePek - Production Deploy Script
echo ====================================================
echo.

cd /d "C:\Users\Hossein Mt\Desktop\GamePek\gamepek-backend"
set PHP=C:\xampp\php\php.exe

:: Put site in maintenance mode
echo [1] Maintenance mode ON...
"%PHP%" artisan down --message="در حال بروزرسانی..." --retry=60

:: Install/update packages
echo.
echo [2] Installing packages...
"%PHP%" composer.phar install --no-dev --optimize-autoloader --no-security-blocking

:: Clear all caches
echo.
echo [3] Clearing caches...
"%PHP%" artisan optimize:clear

:: Run new migrations
echo.
echo [4] Running migrations...
"%PHP%" artisan migrate --force

:: Build optimized cache
echo.
echo [5] Building production cache...
"%PHP%" artisan optimize

:: Set storage permissions
echo.
echo [6] Storage link...
"%PHP%" artisan storage:link --force 2>nul

:: Bring site back online
echo.
echo [7] Maintenance mode OFF...
"%PHP%" artisan up

echo.
echo ====================================================
echo   Deployment complete! http://gamepek.test
echo ====================================================
pause
