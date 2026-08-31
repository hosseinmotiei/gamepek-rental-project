#!/bin/bash

HOSTS="/etc/hosts"
ENTRY="127.0.0.1       gamepek.test"

# Check if already exists
if grep -q "gamepek.test" "$HOSTS" 2>/dev/null; then
  echo "gamepek.test already in hosts file."
  read -p "Press Enter to exit..."
  exit 0
fi

echo "Adding gamepek.test to $HOSTS (requires sudo)..."
echo "$ENTRY" | sudo tee -a "$HOSTS" > /dev/null
if [ $? -ne 0 ]; then
  echo "ERROR: Could not write to $HOSTS. Try running with sudo:"
  echo "  sudo bash $(basename "$0")"
  read -p "Press Enter to exit..." && exit 1
fi

echo ""
echo "Done! Added: $ENTRY"
echo ""
echo "Now point your web server (MAMP/Nginx/Valet) root to:"
echo "  $(cd "$(dirname "$0")" && pwd)/public"
echo ""
echo "Then open: http://gamepek.test"
echo ""
read -p "Press Enter to exit..."
