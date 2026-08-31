@echo off
chcp 65001 >nul
cd /d "C:\Users\Hossein Mt\Desktop\GamePek\gamepek-backend"

echo Creating database if not exists...
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS gamepek CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo.
echo Running migrations...
C:\xampp\php\php.exe artisan migrate --force

echo.
echo Seeding sample data...
C:\xampp\php\php.exe artisan db:seed --force

echo.
echo Creating storage link...
C:\xampp\php\php.exe artisan storage:link --force

echo.
echo Done! Refresh http://gamepek.test
pause
