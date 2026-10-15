<#
    ensure-xampp.ps1 - Nyalakan XAMPP (Apache/MySQL) bila masih mati.

    Dipanggil auto-update-gold.bat SEBELUM `php artisan gold:update`, supaya
    pembaruan harian lewat Task Scheduler (05:15 WIB) tidak gagal hanya karena
    XAMPP belum menyala pada jam tersebut.

    Exit 0 = MySQL siap dipakai (Apache dinyalankan sebagai bonus).
    Exit 1 = MySQL tidak bisa dinyalakan.
#>

function Test-Port([int]$Port) {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $client.Connect('127.0.0.1', $Port)
        $client.Close()
        return $true
    } catch {
        return $false
    }
}

$xamppStart = 'C:\Xampp\xampp_start.exe'
$mysqlUp = Test-Port 3306
$apacheUp = Test-Port 80

if ($mysqlUp -and $apacheUp) {
    Write-Output ('[' + (Get-Date -Format 'dd/MM/yyyy HH:mm') + '] XAMPP sudah menyala (Apache & MySQL).')
    exit 0
}

if (-not $mysqlUp) {
    Write-Output ('[' + (Get-Date -Format 'dd/MM/yyyy HH:mm') + '] MySQL mati - menyalakan XAMPP...')
} else {
    Write-Output ('[' + (Get-Date -Format 'dd/MM/yyyy HH:mm') + '] Apache mati - menyalakan XAMPP...')
}

if (Test-Path $xamppStart) {
    Start-Process -FilePath $xamppStart -WindowStyle Hidden
} else {
    Write-Output ('  ' + $xamppStart + ' tidak ditemukan; akan pakai mysqld langsung.')
}

# Tunggu MySQL siap (wajib untuk update), maksimal 60 detik.
for ($i = 0; $i -lt 20; $i++) {
    if (Test-Port 3306) { break }
    Start-Sleep -Seconds 3
}

# Fallback: jalankan mysqld persis seperti C:\Xampp\mysql_start.bat.
if (-not (Test-Port 3306)) {
    Write-Output '  xampp_start belum memanaskan MySQL; menjalankan mysqld langsung...'
    if (Test-Path 'C:\Xampp\mysql\bin\mysqld.exe') {
        Start-Process -FilePath 'C:\Xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\Xampp\mysql\bin\my.ini' -WorkingDirectory 'C:\Xampp' -WindowStyle Hidden
        for ($i = 0; $i -lt 15; $i++) {
            if (Test-Port 3306) { break }
            Start-Sleep -Seconds 2
        }
    }
}

if (-not (Test-Port 3306)) {
    Write-Output '  GAGAL: MySQL tidak bisa dinyalakan. Update dibatalkan.'
    exit 1
}

$apacheState = if (Test-Port 80) { 'menyala' } else { 'masih mati (web dinyalakan manual)' }
Write-Output ('  MySQL siap. Apache: ' + $apacheState + '.')
exit 0
