@echo off
REM ============================================================
REM  Pembaruan data emas otomatis harian (sumber: Yahoo Finance GC=F)
REM  + melatih ulang model tsQRF bila ada data baru.
REM  Dijadwalkan oleh Windows Task Scheduler (nama task: GoldAutoUpdate).
REM ============================================================
cd /d "%~dp0"

set PHP=C:\xampp\php\php.exe
if not exist "%PHP%" set PHP=php

if not exist "storage\logs" mkdir "storage\logs"

"%PHP%" artisan gold:update --days=90 >> "storage\logs\auto-update.log" 2>&1
