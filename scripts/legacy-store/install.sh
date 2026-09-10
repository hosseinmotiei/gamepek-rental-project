#!/bin/bash

echo "============================================"
echo "  GamePek - Installing Packages"
echo "============================================"
echo ""

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

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
  echo "ERROR: PHP not found. Install via: brew install php"
  read -p "Press Enter to exit..." && exit 1
fi

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

echo "Running composer install..."
echo "(This downloads ~30MB packages - needs internet)"
echo ""
"$PHP_EXE" composer.phar install --no-security-blocking
if [ $? -ne 0 ]; then
  echo ""
  echo "ERROR: composer install failed!"
  echo "Make sure you have internet connection."
  read -p "Press Enter to exit..." && exit 1
fi

echo ""
echo "Generating app key..."
"$PHP_EXE" artisan key:generate --force

echo ""
echo "Creating database..."
if [ -n "$MYSQL_EXE" ]; then
  "$MYSQL_EXE" -u root -e "CREATE DATABASE IF NOT EXISTS gamepek CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
else
  echo "WARNING: MySQL not found. Please create database 'gamepek' manually."
fi

echo ""
echo "Running migrations..."
"$PHP_EXE" artisan migrate --force

echo ""
echo "Seeding sample data..."
"$PHP_EXE" artisan db:seed --force

echo ""
echo "Storage link..."
"$PHP_EXE" artisan storage:link --force

echo ""
echo "============================================"
echo "  Done! Open: http://gamepek.test"
echo "  Mobile: 09100000001  /  OTP: 123456"
echo "============================================"
read -p "Press Enter to exit..."
