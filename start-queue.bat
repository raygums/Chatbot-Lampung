@echo off
title Chatbot Lampung - Queue Worker (Auto-Restart)
color 0A

echo ============================================
echo   Chatbot Lampung - Queue Worker
echo   Auto-restart jika mati
echo   Tekan Ctrl+C untuk berhenti
echo ============================================
echo.

:loop
echo [%date% %time%] Starting queue worker...
php artisan queue:work --timeout=300 --tries=3 --sleep=3
echo.
echo [%date% %time%] Queue worker berhenti! Restart dalam 3 detik...
timeout /t 3 /nobreak >nul
goto loop
