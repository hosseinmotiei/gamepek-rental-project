#!/bin/bash

echo "============================================"
echo "  GamePek - Setup (macOS)"
echo "============================================"
echo ""

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# ─── Detect PHP ──────────────────────────────────────────────────
echo "[1/7] Checking PHP..."
PHP_EXE=""
if command -v php &>/dev/null; then
  PHP_EXE="$(command -v php)"
else
  for p in \
    $(ls /Applications/MAMP/bin/php/php*/bin/php 2>/dev/null | sort -V | tail -1) \
    /opt/homebrew/bin/php \
    /usr/local/bin/php; do
    if [ -x "$p" ]; then PHP_EXE="$p"; break; fi
  done
fi

if [ -z "$PHP_EXE" ]; then
  echo "ERROR: PHP not found."
  echo "Install PHP:  brew install php"
  echo "Or install MAMP from https://www.mamp.info"
  read -p "Press Enter to exit..." && exit 1
fi
echo "OK - PHP: $PHP_EXE"
echo ""

# ─── Detect MySQL ────────────────────────────────────────────────
MYSQL_EXE=""
if command -v mysql &>/dev/null; then
  MYSQL_EXE="$(command -v mysql)"
else
  for p in \
    /Applications/MAMP/Library/bin/mysql \
    /opt/homebrew/bin/mysql \
    /usr/local/bin/mysql; do
    if [ -x "$p" ]; then MYSQL_EXE="$p"; break; fi
  done
fi

# ─── Install Composer ────────────────────────────────────────────
echo "[2/7] Installing Composer..."
if [ -f "composer.phar" ]; then
  echo "Composer already downloaded. Skipping."
else
  echo "Downloading Composer..."
  "$PHP_EXE" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  "$PHP_EXE" composer-setup.php --quiet
  rm -f composer-setup.php
  echo "Composer installed as composer.phar"
fi
echo ""

# ─── Install Laravel Dependencies ────────────────────────────────
echo "[3/7] Installing Laravel packages (this may take a few minutes)..."
if [ -f "vendor/autoload.php" ]; then
  echo "vendor/ already exists. Running composer install..."
fi
"$PHP_EXE" composer.phar install --no-dev --optimize-autoloader
if [ $? -ne 0 ]; then
  echo "ERROR: composer install failed."
  read -p "Press Enter to exit..." && exit 1
fi
echo ""

# ─── Generate App Key ────────────────────────────────────────────
echo "[4/7] Generating application key..."
if [ ! -f ".env" ]; then
  cp .env.example .env
fi
"$PHP_EXE" artisan key:generate --force
echo ""

# ─── Create MySQL Database ───────────────────────────────────────
echo "[5/7] Creating MySQL database..."
if [ -n "$MYSQL_EXE" ]; then
  "$MYSQL_EXE" -u root -e "CREATE DATABASE IF NOT EXISTS gamepek CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1
  if [ $? -ne 0 ]; then
    echo "WARNING: Could not create database automatically."
    echo "Open phpMyAdmin (http://localhost/phpMyAdmin or MAMP) and create a database named: gamepek"
  else
    echo "Database 'gamepek' ready."
  fi
else
  echo "WARNING: MySQL not found in PATH."
  echo "Please create a database named 'gamepek' manually via phpMyAdmin or Sequel Pro."
fi
echo ""

# ─── Run Migrations ──────────────────────────────────────────────
echo "[6/7] Running database migrations..."
"$PHP_EXE" artisan migrate --force
if [ $? -ne 0 ]; then
  echo "ERROR: Migration failed. Check DB_* settings in .env"
  read -p "Press Enter to exit..." && exit 1
fi
echo ""

# ─── Seed Database ───────────────────────────────────────────────
echo "[7/7] Seeding database with sample data..."
"$PHP_EXE" artisan db:seed --force
echo ""

# ─── Storage Link & Cache ────────────────────────────────────────
echo "Creating storage symlink..."
"$PHP_EXE" artisan storage:link --force 2>&1
echo ""

echo "Clearing caches..."
"$PHP_EXE" artisan config:clear
"$PHP_EXE" artisan cache:clear
"$PHP_EXE" artisan view:clear
echo ""

echo "============================================"
echo "  Setup Complete!"
echo "============================================"
echo ""
echo "Next steps:"
echo "  1. Add to /etc/hosts (run add-hosts.sh):"
echo "     127.0.0.1    gamepek.test"
echo ""
echo "  2. Point your web server (MAMP/Nginx/Valet) to: $(pwd)/public"
echo ""
echo "  3. Open: http://gamepek.test"
echo ""
echo "  Login info:"
echo "    Mobile:  09100000001"
echo "    OTP:     123456"
echo ""
read -p "Press Enter to exit..."
