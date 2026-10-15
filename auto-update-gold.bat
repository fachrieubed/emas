@echo off
REM ============================================================
REM  Pembaruan data emas otomatis harian (sumber: Yahoo Finance GC=F)
REM  + melatih ulang model tsQRF bila ada data baru.
REM  Dijadwalkan oleh Windows Task Scheduler (nama task: GoldAutoUpdate).
REM
REM  Sebelum update, script ini menyalakan XAMPP (Apache/MySQL) bila masih
REM  mati — supaya update 05:15 WIB tidak gagal koneksi database seperti
REM  yang pernah terjadi saat XAMPP belum dinyalakan.
REM ============================================================
cd /d "%~dp0"

set PHP=C:\xampp\php\php.exe
if not exist "%PHP%" set PHP=php

if not exist "storage\logs" mkdir "storage\logs"

REM --- 1. Pastikan XAMPP/MySQL menyala (lihat ensure-xampp.ps1) ---
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0ensure-xampp.ps1" >> "storage\logs\auto-update.log" 2>&1
if errorlevel 1 (
    echo [%date% %time%] GAGAL: MySQL/XAMPP tidak bisa dinyalakan, update dibatalkan. >> "storage\logs\auto-update.log"
    exit /b 1
)

REM --- 2. Sinkronisasi data + latih ulang model tsQRF ---
"%PHP%" artisan gold:update --days=90 >> "storage\logs\auto-update.log" 2>&1
