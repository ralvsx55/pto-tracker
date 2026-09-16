@echo off
rem PTO Tracker local server (SPEC section 12). XAMPP's MariaDB must be running.
rem First run: C:\xampp\php\php.exe pto_app\tools\dev_reset.php
cd /d "%~dp0"
echo PTO Tracker on http://127.0.0.1:8020/   (Ctrl+C stops it)
C:\xampp\php\php.exe -S 127.0.0.1:8020 -t public_html\pto
