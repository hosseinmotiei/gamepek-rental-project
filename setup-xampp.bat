@echo off
chcp 65001 >nul
echo ============================================
echo   GamePek - XAMPP Setup
echo ============================================
echo.

set PHP=C:\xampp\php\php.exe
set PROJECT=C:\Users\Hossein Mt\Desktop\GamePek\gamepek-backend
set MYSQL=C:\xampp\mysql\bin\mysql.exe

cd /d "%PROJECT%"

:: ─── Step 1: Check PHP ──────────────────────────────────────
echo [1/7] Checking PHP...
"%PHP%" --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: PHP not found at %PHP%
    pause & exit /b 1
)
echo OK - PHP found.
echo.

:: ─── Step 2: Download and install Composer ──────────────────
echo [2/7] Installing Composer...
if exist "%PROJECT%\composer.phar" (
    echo Composer already downloaded. Skipping.
) else (
    echo Downloading Composer...
    "%PHP%" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    "%PHP%" composer-setup.php --quiet
    del composer-setup.php >nul 2>&1
    echo Composer installed as composer.phar
)
echo.

:: ─── Step 3: Install Laravel dependencies ───────────────────
echo [3/7] Installing Laravel packages (this may take a few minutes)...
if exist "%PROJECT%\vendor\autoload.php" (
    echo vendor/ already exists. Running composer update...
    "%PHP%" composer.phar install --no-dev --optimize-autoloader 2>&1
) else (
    "%PHP%" composer.phar install 2>&1
)
if errorlevel 1 (
    echo ERROR: composer install failed.
    pause & exit /b 1
)
echo.

:: ─── Step 4: Generate App Key ───────────────────────────────
echo [4/7] Generating application key...
"%PHP%" artisan key:generate --force
echo.

:: ─── Step 5: Create MySQL database ─────────────────────────
echo [5/7] Creating MySQL database...
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS gamepek CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1
if errorlevel 1 (
    echo WARNING: Could not create database automatically.
    echo Please open phpMyAdmin at http://localhost/phpmyadmin
    echo and create a database named: gamepek
    echo.
) else (
    echo Database 'gamepek' ready.
)
echo.

:: ─── Step 6: Run Migrations ─────────────────────────────────
echo [6/7] Running database migrations...
"%PHP%" artisan migrate --force
if errorlevel 1 (
    echo ERROR: Migration failed. Check DB_* settings in .env
    pause & exit /b 1
)
echo.

:: ─── Step 7: Seed Database ──────────────────────────────────
echo [7/7] Seeding database with sample data...
"%PHP%" artisan db:seed --force
echo.

:: ─── Storage Link ────────────────────────────────────────────
echo Creating storage symlink...
"%PHP%" artisan storage:link --force 2>&1
echo.

:: ─── Cache Clear ─────────────────────────────────────────────
echo Clearing caches...
"%PHP%" artisan config:clear
"%PHP%" artisan cache:clear
"%PHP%" artisan view:clear
echo.

echo ============================================
echo   Setup Complete!
echo ============================================
echo.
echo Next steps:
echo   1. Add to C:\Windows\System32\drivers\etc\hosts:
echo      127.0.0.1    gamepek.test
echo.
echo   2. Restart Apache in XAMPP Control Panel
echo.
echo   3. Open: http://gamepek.test
echo.
echo   Login info:
echo     Mobile:  09100000001
echo     OTP:     123456
echo.
pause
