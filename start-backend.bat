@echo off
setlocal

cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo ERROR: PHP tidak ditemukan.
    echo Tambahkan folder PHP Laragon ke PATH Windows, lalu buka terminal baru.
    echo Contoh folder: C:\laragon\bin\php\php-8.x.x
    pause
    exit /b 1
)

echo Backend berjalan di http://localhost:8000
echo Document root: %CD%\public
echo Tekan Ctrl+C untuk menghentikan server.
echo.

php -S localhost:8000 -t public

echo.
echo Server telah berhenti.
pause
