#!/bin/bash

echo "===================================================="
echo "  GamePek - Production Deploy Script"
echo "===================================================="
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

echo "[1] Maintenance mode ON..."
"$PHP_EXE" artisan down --message="در حال بروزرسانی..." --retry=60

echo ""
echo "[2] Installing packages..."
"$PHP_EXE" composer.phar install --no-dev --optimize-autoloader --no-security-blocking

echo ""
echo "[3] Clearing caches..."
"$PHP_EXE" artisan optimize:clear

echo ""
echo "[4] Running migrations..."
"$PHP_EXE" artisan migrate --force

echo ""
echo "[5] Building production cache..."
"$PHP_EXE" artisan optimize

echo ""
echo "[6] Storage link..."
"$PHP_EXE" artisan storage:link --force 2>/dev/null

echo ""
echo "[7] Maintenance mode OFF..."
"$PHP_EXE" artisan up

echo ""
echo "===================================================="
echo "  Deployment complete! http://gamepek.test"
echo "===================================================="
read -p "Press Enter to exit..."
