<?php

namespace App\Support;

use RuntimeException;

/**
 * Menjalankan proses background di Windows (cmd: start /B) agar request web
 * tidak tergantung pada proses pelatihan yang memakan waktu.
 *
 * CATATAN: di bawah Apache/mod_php nilai PHP_BINARY menunjuk ke httpd.exe,
 * bukan php.exe. Oleh karena itu binary PHP di-resolve secara eksplisit:
 *  - CLI  -> PHP_BINARY (mis. C:\xampp\php\php.exe)
 *  - Web  -> PHP_BINDIR/php.exe (mis. C:\xampp\php\php.exe untuk XAMPP)
 */
class BackgroundProcess
{
    public static function phpBinary(): string
    {
        $candidates = [];

        if (PHP_SAPI === 'cli' && PHP_BINARY) {
            $candidates[] = PHP_BINARY;
        }

        if (PHP_BINDIR) {
            $candidates[] = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php.exe';
        }

        // Fallback umum XAMPP bila konstanta di atas tidak bisa diandalkan
        $candidates[] = 'C:\\xampp\\php\\php.exe';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Tidak dapat menemukan php.exe untuk menjalankan proses background.');
    }

    /**
     * @param array<int, string> $args daftar argumen setelah nama skrip
     */
    public static function spawnPhp(array $args): bool
    {
        try {
            $php = escapeshellarg(self::phpBinary());
        } catch (RuntimeException $e) {
            return false;
        }

        $script = escapeshellarg(base_path('artisan'));

        $pieces = [];
        foreach ($args as $arg) {
            $pieces[] = escapeshellarg((string) $arg);
        }

        // 'start /B' = jalankan tanpa jendela baru, lanjut segera
        $cmd = 'start /B "" ' . $php . ' ' . $script . ' ' . implode(' ', $pieces) . ' > NUL 2>&1';

        $handle = popen($cmd, 'r');
        if ($handle === false) {
            return false;
        }
        pclose($handle);

        return true;
    }
}