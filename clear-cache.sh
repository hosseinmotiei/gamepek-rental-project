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
  echo "ERROR: PHP was not found. Install via: brew install php"
  read -p "Press Enter to exit..." && exit 1
fi

"$PHP_EXE" artisan config:clear
"$PHP_EXE" artisan cache:clear
"$PHP_EXE" artisan view:clear
"$PHP_EXE" artisan route:clear
echo "Done! Refresh the browser."
read -p "Press Enter to exit..."
