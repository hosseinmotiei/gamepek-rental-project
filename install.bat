@echo off
chcp 65001 >nul
echo ============================================
echo   GamePek - Installing Packages
echo ============================================
echo.

cd /d "C:\Users\Hossein Mt\Desktop\GamePek\gamepek-backend"

echo Running composer install...
echo (This downloads ~30MB packages - needs internet)
echo.
C:\xampp\php\php.exe composer.phar install --no-security-blocking
if errorlevel 1 (
    echo.
    echo ERROR: composer install failed!
    echo Make sure you have internet connection.
    pause
    exit /b 1
)

echo.
echo Generating app key...
C:\xampp\php\php.exe artisan key:generate --force

echo.
echo Creating database...
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS gamepek CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo.
echo Running migrations...
C:\xampp\php\php.exe artisan migrate --force

echo.
echo Seeding sample data...
C:\xampp\php\php.exe artisan db:seed --force

echo.
echo Storage link...
C:\xampp\php\php.exe artisan storage:link --force

echo.
echo ============================================
echo   Done! Open: http://gamepek.test
echo   Mobile: 09100000001  /  OTP: 123456
echo ============================================
pause
