@echo off
cd /d "C:\Users\Hossein Mt\Desktop\GamePek\gamepek-backend"
C:\xampp\php\php.exe artisan config:clear
C:\xampp\php\php.exe artisan cache:clear
C:\xampp\php\php.exe artisan view:clear
C:\xampp\php\php.exe artisan route:clear
echo Done! Refresh the browser.
pause
