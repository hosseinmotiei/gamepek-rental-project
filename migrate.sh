#!/bin/bash

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

echo "Creating database if not exists..."
if [ -n "$MYSQL_EXE" ]; then
  "$MYSQL_EXE" -u root -e "CREATE DATABASE IF NOT EXISTS gamepek CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
else
  echo "WARNING: MySQL not found. Make sure database 'gamepek' exists."
fi

echo ""
echo "Running migrations..."
"$PHP_EXE" artisan migrate --force

echo ""
echo "Seeding sample data..."
"$PHP_EXE" artisan db:seed --force

echo ""
echo "Creating storage link..."
"$PHP_EXE" artisan storage:link --force

echo ""
echo "Done! Refresh http://gamepek.test"
read -p "Press Enter to exit..."
