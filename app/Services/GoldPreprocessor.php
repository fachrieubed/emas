<?php

namespace App\Services;

/**
 * Preprocessing & feature engineering sesuai panduan tsQRF:
 *  - urutkan berdasarkan tanggal
 *  - drop baris yang tidak lengkap
 *  - fitur: Pembukaan, Tertinggi, Terendah, Vol., Perubahan%, Lag_1, Lag_2, Lag_3
 *  - target = harga penutupan periode berikutnya (shift -1)
 *
 * CATATAN PENTING (perbaikan akurasi):
 * Random Forest berbasis pohon TIDAK BISA mengekstrapolasi di luar rentang data
 * latih. Jika target berupa harga absolut (mis. 3.725 pada data latih), maka
 * ketika harga terkini naik di atas 3.725 (mis. 4.189) semua sampel uji jatuh ke
 * daun terujung yang sama sehingga prediksi nyangkut konstan dan meleset belasan
 * persen. Karena itu fitur dan target dinyatakan sebagai RASIO (persen) terhadap
 * harga penutupan hari yang sama — sifatnya stacioner sehingga model dapat
 * digeneralisasi ke level harga mana pun. Prediksi harga akhir dihitung:
 *
 *     harga prediksi = penutupan terakhir x (1 + return prediksi%)
 *
 * sehingga prediksi selalu mengikuti level harga terkini.
 */
class GoldPreprocessor
{
    public const FEATURES = [
        'pembukaan/penutupan',
        'tertinggi/penutupan',
        'terendah/penutupan',
        'volume',
        'perubahan%',
        'lag1/penutupan',
        'lag2/penutupan',
        'lag3/penutupan',
    ];

    /**
     * @param array<int, array<string, mixed>> $rows hasil CsvParser
     * @return array{
     *   dates: string[], sampleDates: string[], anchors: float[],
     *   X: array<int, float[]>, y: float[],
     *   rows: array<int, array<string, mixed>>,
     *   total: int, model_rows: int, has_volume: bool, has_change: bool
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
        $close = [];
        foreach ($clean as $r) {
            $dates[] = $r['tanggal'];
            $close[] = (float) $r['terakhir'];
        }

        $X = [];
        $y = [];
        $anchors = [];       // penutupan hari ke-i (dasar mengubah return jadi harga)
        $sampleDates = [];   // tanggal target (hari ke-i+1)
        $hasVolume = true;
        $hasChange = true;

        for ($i = 3; $i < $n - 1; $i++) {
            $open = (float) $clean[$i]['pembukaan'];
            $high = (float) $clean[$i]['tertinggi'];
            $low = (float) $clean[$i]['terendah'];
            $c = $close[$i];
            $prev = $close[$i - 1];

            // hindari pembagian nol / nilai tidak masuk akal
            if ($c <= 0 || $prev <= 0 || $open <= 0) {
                continue;
            }

            $volume = 0.0;
            if (isset($clean[$i]['volume']) && $clean[$i]['volume'] !== null && $clean[$i]['volume'] !== '') {
                $volume = (float) $clean[$i]['volume'];
            } else {
                $hasVolume = false;
            }

            $change = ($c - $prev) / $prev * 100;
            if (isset($clean[$i]['perubahan']) && $clean[$i]['perubahan'] !== null && $clean[$i]['perubahan'] !== '' && is_numeric($clean[$i]['perubahan'])) {
                $change = (float) $clean[$i]['perubahan'];
            } else {
                $hasChange = false;
            }

            $X[] = [
                ($open - $c) / $c * 100,                          // pembukaan vs penutupan hari itu
                ($high - $c) / $c * 100,                           // jarak tertinggi ke penutupan
                ($low - $c) / $c * 100,                            // jarak terendah ke penutupan
                $volume,                                           // volume
                $change,                                           // perubahan % hari itu
                ($prev - $c) / $c * 100,                           // lag1 terhadap penutupan
                ($close[$i - 2] - $c) / $c * 100,                  // lag2 terhadap penutupan
                ($close[$i - 3] - $c) / $c * 100,                  // lag3 terhadap penutupan
            ];
            $y[] = ($close[$i + 1] - $c) / $c * 100;               // target: return hari berikutnya (%)
            $anchors[] = $c;
            $sampleDates[] = $dates[$i + 1];
        }

        if (count($X) === 0) {
            throw new \RuntimeException('Data terlalu sedikit untuk membangun fitur (minimal 5 baris).');
        }

        return [
            'dates' => $dates,
            'sampleDates' => $sampleDates,
            'anchors' => $anchors,
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
