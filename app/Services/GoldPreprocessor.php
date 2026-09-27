<?php

namespace App\Services;

/**
 * Preprocessing & feature engineering sesuai panduan tsQRF:
 *  - urutkan berdasarkan tanggal
 *  - drop baris tidak lengkap (sudah dilakukan di parser, diulang untuk keamanan)
 *  - buat fitur Lag_1, Lag_2, Lag_3 dari Terakhir
 *  - target = Terakhir periode berikutnya (shift -1)
 *
 * Fitur yang dipakai:
 *   Pembukaan, Tertinggi, Terendah, Vol., Perubahan%, Lag_1, Lag_2, Lag_3
 */
class GoldPreprocessor
{
    public const FEATURES = ['pembukaan', 'tertinggi', 'terendah', 'volume', 'perubahan', 'lag1', 'lag2', 'lag3'];

    /**
     * @param array<int, array<string, mixed>> $rows hasil CsvParser
     * @return array{
     *   dates: string[], X: array<int, float[]>, y: float[],
     *   total: int, has_volume: bool, has_change: bool
     * }
     */
    public function build(array $rows): array
    {
        // sortir berdasarkan tanggal
        usort($rows, fn ($a, $b) => strcmp($a['tanggal'], $b['tanggal']));

        // drop baris yang punya nilai invalid
        $clean = array_values(array_filter($rows, fn ($r) =>
            $this->valid($r['terakhir']) && $this->valid($r['pembukaan'])
            && $this->valid($r['tertinggi']) && $this->valid($r['terendah'])
            && isset($r['tanggal'])
        ));

        $n = count($clean);
        $dates = [];
        $terakhir = [];
        foreach ($clean as $r) {
            $dates[] = $r['tanggal'];
            $terakhir[] = (float) $r['terakhir'];
        }

        $X = [];
        $y = [];
        $hasVolume = true;
        $hasChange = true;

        for ($i = 3; $i < $n - 1; $i++) {
            $lag1 = $terakhir[$i - 1];
            $lag2 = $terakhir[$i - 2];
            $lag3 = $terakhir[$i - 3];

            $volume = isset($clean[$i]['volume']) ? (float) $clean[$i]['volume'] : 0.0;
            $perubahan = isset($clean[$i]['perubahan']) ? (float) $clean[$i]['perubahan'] : 0.0;
            if ($clean[$i]['volume'] === null || $clean[$i]['volume'] === '') {
                $hasVolume = false;
            }
            if ($clean[$i]['perubahan'] === null || $clean[$i]['perubahan'] === '') {
                $hasChange = false;
            }

            $X[] = [
                (float) $clean[$i]['pembukaan'],
                (float) $clean[$i]['tertinggi'],
                (float) $clean[$i]['terendah'],
                $volume,
                $perubahan,
                $lag1,
                $lag2,
                $lag3,
            ];
            $y[] = (float) $clean[$i + 1]['terakhir'];
        }

        if (count($X) === 0) {
            throw new \RuntimeException('Data terlalu sedikit untuk membangun fitur (minimal 5 baris).');
        }

        return [
            'dates' => $dates,
            'X' => $X,
            'y' => $y,
            'rows' => $clean,
            'total' => $n,
            'model_rows' => count($X),
            'has_volume' => $hasVolume,
            'has_change' => $hasChange,
        ];
    }

    private function valid($v): bool
    {
        return $v !== null && $v !== '' && is_numeric($v);
    }
}