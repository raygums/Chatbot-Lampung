@echo off
title Chatbot Lampung - Launcher
color 0B

echo ============================================
echo   Chatbot Lampung - Start All Services
echo ============================================
echo.

echo [%date% %time%] Starting Laravel server...
start "Laravel Server" cmd /k "cd /d %~dp0 && php artisan serve"

echo [%date% %time%] Starting Queue Worker (auto-restart)...
start "Queue Worker" cmd /k "cd /d %~dp0 && start-queue.bat"

echo.
echo ============================================
echo   Semua service sudah jalan!
echo   - Laravel Server: http://localhost:8000
echo   - Queue Worker: auto-restart enabled
echo ============================================
echo.
echo Tutup window ini tidak akan mematikan service.
echo Untuk stop, tutup masing-masing window.
pause
