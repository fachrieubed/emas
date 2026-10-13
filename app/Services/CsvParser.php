<?php

namespace App\Services;

use RuntimeException;

/**
 * Membaca file CSV data emas dengan format Indonesia
 * (titik sebagai pemisah ribuan, koma sebagai desimal, volume memakai K/M/B).
 *
 * Kolom yang diharapkan:
 *   Tanggal | Terakhir | Pembukaan | Tertinggi | Terendah | Vol. | Perubahan%
 */
class CsvParser
{
    public const EXPECTED_HEADERS = [
        'tanggal' => ['tanggal', 'date', 'tgl'],
        'terakhir' => ['terakhir', 'terakhir*', 'last', 'close', 'harga', 'harga emas'],
        'pembukaan' => ['pembukaan', 'open', 'pembuka'],
        'tertinggi' => ['tertinggi', 'high', 'max'],
        'terendah' => ['terendah', 'low', 'min'],
        'volume' => ['vol.', 'vol', 'volume'],
        'perubahan' => ['perubahan%', 'perubahan', 'chg%', 'change%', 'change', 'perubahan %'],
    ];

    /** Jumlah baris dibuang karena jatuh pada hari Sabtu/Minggu (bukan hari perdagangan). */
    private int $weekendDropped = 0;

    /** Jumlah baris dibuang karena ada kolom kosong atau tanggal tidak valid. */
    private int $incompleteDropped = 0;

    /**
     * @return array{rows: array<int, array<string,mixed>>, header: array<int,string>, delimiter: string, total_rows: int, dropped: int, dropped_weekend: int, dropped_incomplete: int, errors: array<int,string>}
     */
    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('File kosong atau tidak dapat dibaca.');
        }

        // BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $delimiter = $this->detectDelimiter($content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if (count($lines) < 2) {
            throw new RuntimeException('File harus memiliki minimal 2 baris (header + data).');
        }

        $header = str_getcsv(trim($lines[0], "\xEF\xBB\xBF"), $delimiter);
        $header = array_map(fn ($h) => trim($h, " \t\"'"), $header);

        $map = $this->mapHeaders($header);

        $rows = [];
        $dropped = 0;
        $errors = [];
        $this->weekendDropped = 0;
        $this->incompleteDropped = 0;

        for ($i = 1; $i < count($lines); $i++) {
            $raw = str_getcsv($lines[$i], $delimiter);
            if (count($raw) < 5) {
                continue;
            }

            $row = [];
            foreach ($map as $key => $colIndex) {
                $row[$key] = $raw[$colIndex] ?? null;
            }

            $parsed = $this->parseRow($row);
            if ($parsed === null) {
                $dropped++;
                continue;
            }

            $rows[] = $parsed;
        }

        if (count($rows) === 0) {
            throw new RuntimeException('Tidak ada baris data valid yang ditemukan. Cek format kolom (Tanggal, Terakhir, Pembukaan, Tertinggi, Terendah, Vol., Perubahan%).');
        }

        return [
            'rows' => $rows,
            'header' => $header,
            'delimiter' => $delimiter,
            'total_rows' => count($rows) + $dropped,
            'dropped' => $dropped,
            'dropped_weekend' => $this->weekendDropped,
            'dropped_incomplete' => $this->incompleteDropped,
            'errors' => $errors,
        ];
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n");

        foreach ([';', ',', "\t"] as $delim) {
            $parts = str_getcsv($firstLine, $delim);
            $count = count(array_filter(array_map('trim', $parts)));
            // ';' dan "\t" adalah pemisah yang paling umum untuk format Indonesia.
            if ($delim === ';' && $count >= 6 && $count <= 9) {
                return ';';
            }
            if ($delim === "\t" && $count >= 6 && $count <= 12) {
                return "\t";
            }
            if ($delim === ',' && $count >= 6 && $count <= 9) {
                return ',';
            }
        }

        return ';';
    }

    /**
     * @return array<string, int> peta nama kolom internal -> index kolom CSV
     */
    private function mapHeaders(array $header): array
    {
        $map = [];
        $normalized = array_map(fn ($h) => strtolower(trim($h, " \t\"'")), $header);

        foreach ($normalized as $idx => $name) {
            foreach (self::EXPECTED_HEADERS as $key => $aliases) {
                if (in_array($name, $aliases, true) && !isset($map[$key])) {
                    $map[$key] = $idx;
                    break;
                }
            }
        }

        $required = ['tanggal', 'terakhir', 'pembukaan', 'tertinggi', 'terendah'];
        foreach ($required as $r) {
            if (!isset($map[$r])) {
                throw new RuntimeException("Kolom wajib '{$r}' tidak ditemukan pada header CSV.");
            }
        }

        return $map;
    }

    /**
     * @param array<string, string|null> $row
     * @return array<string, mixed>|null
     */
    private function parseRow(array $row): ?array
    {
        $tanggal = $this->parseDate($row['tanggal'] ?? '');
        if ($tanggal === null) {
            $this->incompleteDropped++;
            return null;
        }

        // Sabtu/Minggu bukan hari perdagangan emas -> dibuang.
        if ($this->isWeekend($tanggal)) {
            $this->weekendDropped++;
            return null;
        }

        $terakhir = $this->parseIdNumber($row['terakhir'] ?? null);
        $pembukaan = $this->parseIdNumber($row['pembukaan'] ?? null);
        $tertinggi = $this->parseIdNumber($row['tertinggi'] ?? null);
        $terendah = $this->parseIdNumber($row['terendah'] ?? null);
        $volume = $this->parseVolume($row['volume'] ?? null);
        $perubahan = $this->parsePercent($row['perubahan'] ?? null);

        // Tidak boleh ada kolom kosong: seluruh kolom wajib terisi.
        if ($terakhir === null || $pembukaan === null || $tertinggi === null
            || $terendah === null || $volume === null || $perubahan === null) {
            $this->incompleteDropped++;
            return null;
        }

        return [
            'tanggal' => $tanggal,
            'terakhir' => $terakhir,
            'pembukaan' => $pembukaan,
            'tertinggi' => $tertinggi,
            'terendah' => $terendah,
            'volume' => $volume,
            'perubahan' => $perubahan,
        ];
    }

    /** Hari Sabtu (6) atau Minggu (7) pada kalender. */
    private function isWeekend(string $isoDate): bool
    {
        return (int) date('N', strtotime($isoDate)) >= 6;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(str_replace(['"', "'"], '', $value));
        if ($value === '') {
            return null;
        }

        $formats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y', 'j/n/Y', 'j-n-Y', 'Y-m-d H:i:s', 'd/m/Y H:i:s'];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $value);
            if ($dt !== false) {
                $errors = \DateTime::getLastErrors();
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return $dt->format('Y-m-d');
                }
            }
        }

        // fallback strtotime untuk format lain
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    /**
     * Parse angka format Indonesia: "4.805,46" -> 4805.46, "1.234" -> 1234.0
     */
    private function parseIdNumber(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $s = trim(str_replace(['"', "'"], '', $value));
        if ($s === '' || $s === '-' || $s === '—') {
            return null;
        }

        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        $result = $this->parseNumberString($s);
        if ($result === null) {
            return null;
        }

        return $neg ? -$result : $result;
    }

    /**
     * Inti parsing: menangani "1.234,56", "1234.56", "1,234,567.89".
     */
    private function parseNumberString(string $s): ?float
    {
        $s = trim($s);
        $hasDot = str_contains($s, '.');
        $hasComma = str_contains($s, ',');

        if ($hasDot && $hasComma) {
            // Ambigu: tentukan mana pemisah ribuan vs desimal
            $lastDot = strrpos($s, '.');
            $lastComma = strrpos($s, ',');

            if ($lastComma > $lastDot) {
                // format Eropa/Indonesia: "1.234,56" -> ribuan pakai titik
                $num = str_replace('.', '', $s);
                $num = str_replace(',', '.', $num);
            } else {
                // format US: "1,234.56" -> ribuan pakai koma
                $num = str_replace(',', '', $s);
            }
        } elseif ($hasComma && !$hasDot) {
            // "1234,56" - kemungkinan desimal koma (Indonesia)
            if (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
                $num = str_replace(',', '', $s); // ribuan, contoh "1,234,567"
            } else {
                $num = str_replace(',', '.', $s); // desimal, contoh "1234,56"
            }
        } else {
            // hanya titik: "1.234" atau "1234.56"
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
                $num = str_replace('.', '', $s); // ribuan "1.234"
            } else {
                $num = $s; // desimal biasa "1234.56"
            }
        }

        if (!is_numeric($num)) {
            return null;
        }

        return (float) $num;
    }

    /**
     * Volume: "40,86K" -> 40860, "1,2M" -> 1200000, "500" -> 500
     */
    private function parseVolume(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $s = trim(str_replace(['"', "'"], '', $value));
        if ($s === '' || $s === '-' || in_array(strtolower($s), ['n/a', 'null', 'nan'], true)) {
            return null;
        }

        $suffix = 1.0;
        $u = strtoupper(str_replace(' ', '', $s));
        if (str_ends_with($u, 'K')) {
            $suffix = 1000.0;
            $u = substr($u, 0, -1);
        } elseif (str_ends_with($u, 'M')) {
            $suffix = 1000000.0;
            $u = substr($u, 0, -1);
        } elseif (str_ends_with($u, 'B')) {
            $suffix = 1000000000.0;
            $u = substr($u, 0, -1);
        }

        $num = $this->parseNumberString($u);
        if ($num === null) {
            return null;
        }

        return $num * $suffix;
    }

    /**
     * Perubahan%: "-0,48%" -> -0.48
     */
    private function parsePercent(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $s = trim(str_replace(['"', "'", '%'], '', $value));
        if ($s === '' || $s === '-') {
            return null;
        }
        $num = $this->parseNumberString($s);

        return $num;
    }
}