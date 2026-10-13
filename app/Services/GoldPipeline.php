<?php

namespace App\Services;

use App\Services\ML\Quantile;
use App\Services\ML\RandomForest;
use RuntimeException;

/**
 * Pipeline model tsQRF: preprocessing -> train/test split (80/20 berurutan)
 * -> latih Random Forest -> prediksi quantile Q10/Q50/Q90 -> evaluasi
 * -> peramalan (forecast) N hari ke depan secara rekursif.
 *
 * Model bekerja pada ruang RETURN (%), bukan harga absolut, sehingga tetap
 * dapat digeneralisasi ketika harga terkini berada di luar rentang data latih.
 * Semua nilai yang disimpan/ditampilkan (metrik, tabel uji, forecast) tetap
 * dikonversi kembali ke satuan harga.
 */
class GoldPipeline
{
    /**
     * @param array $pre hasil GoldPreprocessor::build()
     * @param array $params konfigurasi model
     * @param callable|null $progress callback(string $message)
     * @return array
     */
    public function run(array $pre, array $params, ?callable $progress = null): array
    {
        $notify = fn (string $msg) => is_callable($progress) ? $progress($msg) : null;

        $X = $pre['X'];
        $y = $pre['y'];
        $dates = $pre['dates'];
        $rows = $pre['rows'];
        $anchors = $pre['anchors'];         // penutupan hari ke-i untuk tiap sampel
        $sampleDates = $pre['sampleDates']; // tanggal target (hari ke-i+1)
        $terakhir = array_map(fn ($r) => (float) $r['terakhir'], $rows);

        $n = count($y);
        $splitRatio = (float) ($params['split_ratio'] ?? 0.80);
        $horizon = max(1, min(30, (int) ($params['horizon'] ?? 7)));

        $split = (int) round($n * $splitRatio);
        if ($split <= 0 || $split >= $n) {
            throw new RuntimeException('Pembagian train/test tidak valid.');
        }

        $XTrain = array_values(array_slice($X, 0, $split));
        $yTrain = array_values(array_slice($y, 0, $split));
        $XTest = array_values(array_slice($X, $split));
        $yTest = array_values(array_slice($y, $split));
        $testAnchors = array_values(array_slice($anchors, $split));
        $testDates = array_values(array_slice($sampleDates, $split));

        $notify('Melatih Random Forest (' . count($XTrain) . ' baris latih)...');

        $rf = new RandomForest([
            'n_estimators' => (int) ($params['n_estimators'] ?? 500),
            'min_samples_leaf' => (int) ($params['min_samples_leaf'] ?? 5),
            'min_samples_split' => (int) ($params['min_samples_split'] ?? 2),
            'max_features' => $params['max_features'] ?? 'sqrt',
            'random_state' => (int) ($params['random_state'] ?? 42),
        ]);

        $t0 = microtime(true);
        $rf->fit($XTrain, $yTrain);
        $trainingSeconds = microtime(true) - $t0;

        // --- Prediksi quantile pada data testing (dinilai pada skala harga) ---
        $notify('Menghitung prediksi quantile pada data testing...');
        $testRows = [];
        $nTest = count($yTest);
        $mae = 0.0;
        $rmse = 0.0;
        $mape = 0.0;
        $covered = 0;
        $wSum = 0.0;

        for ($i = 0; $i < $nTest; $i++) {
            $dist = $rf->predictAllTreesOne($XTest[$i]);
            $iv = Quantile::interval($dist, 0.10);

            // konversi return (%) -> harga
            $anchor = $testAnchors[$i];
            $actual = $anchor * (1 + $yTest[$i] / 100);
            $q10 = $anchor * (1 + $iv['q10'] / 100);
            $q50 = $anchor * (1 + $iv['q50'] / 100);
            $q90 = $anchor * (1 + $iv['q90'] / 100);

            $err = $actual - $q50;
            $mae += abs($err);
            $rmse += $err * $err;
            if ($actual != 0.0) {
                $mape += abs($err / $actual);
            }
            if ($actual >= $q10 && $actual <= $q90) {
                $covered++;
            }
            $wSum += $q90 - $q10;

            $testRows[] = [
                'tanggal' => $testDates[$i],
                'actual' => $actual,
                'q10' => $q10,
                'q50' => $q50,
                'q90' => $q90,
            ];
        }

        $metrics = [
            'mae' => $nTest > 0 ? $mae / $nTest : 0.0,
            'rmse' => $nTest > 0 ? sqrt($rmse / $nTest) : 0.0,
            'mape' => $nTest > 0 ? ($mape / $nTest) * 100 : 0.0,
            'coverage' => $nTest > 0 ? ($covered / $nTest) * 100 : 0.0,
            'avg_interval_width' => $nTest > 0 ? $wSum / $nTest : 0.0,
        ];

        // --- Forecast N hari ke depan (rekursif, dikunci ke harga penutupan terakhir) ---
        $notify('Menyusun peramalan ' . $horizon . ' hari ke depan...');
        $forecastRows = $this->forecast($rf, $rows, $terakhir, $horizon);
        $totalSeconds = microtime(true) - $t0;

        return [
            'model' => $rf,
            'metrics' => $metrics,
            'test' => $testRows,
            'forecast' => $forecastRows,
            'training_seconds' => $trainingSeconds,
            'total_seconds' => $totalSeconds,
            'split' => $split,
            'n' => $n,
            'train_rows' => count($XTrain),
            'test_rows' => count($XTest),
            'dates' => $dates,
            'sampleDates' => $sampleDates,
        ];
    }

    /**
     * Peramalan rekursif pada ruang return:
     *  - fitur dibangun sebagai rasio terhadap penutupan "hari itu"
     *  - OHLC/volume hari depan diasumsikan mengikuti pola observasi terakhir
     *  - harga prediksi = penutupan "hari itu" x (1 + return%)
     *
     * Dengan cara ini prediksi selalu berpusat pada level harga terkini
     * (bukan terjebak di rentang data latih).
     *
     * @param array<int, array<string, mixed>> $rows baris bersih (sudah urut)
     * @param array<int, float> $terakhir
     * @return array<int, array{tanggal:string, q10:float, q50:float, q90:float}>
     */
    private function forecast(RandomForest $rf, array $rows, array $terakhir, int $horizon): array
    {
        $n = count($terakhir);
        if ($n < 4) {
            throw new RuntimeException('Data terlalu sedikit untuk peramalan (minimal 4 baris).');
        }
        $lastQuote = $rows[$n - 1];

        $lastClose = $terakhir[$n - 1];
        $lastDate = $lastQuote['tanggal'];

        if ($lastClose <= 0) {
            throw new RuntimeException('Harga penutupan terakhir tidak valid.');
        }

        // pola OHLC terakhir dinyatakan sebagai rasio terhadap penutupannya
        $open = isset($lastQuote['pembukaan']) ? (float) $lastQuote['pembukaan'] : $lastClose;
        $high = isset($lastQuote['tertinggi']) ? (float) $lastQuote['tertinggi'] : $lastClose;
        $low = isset($lastQuote['terendah']) ? (float) $lastQuote['terendah'] : $lastClose;
        $vol = isset($lastQuote['volume']) && $lastQuote['volume'] !== null ? (float) $lastQuote['volume'] : 0.0;

        $bodyRatio = $open > 0 ? ($open - $lastClose) / $lastClose * 100 : 0.0;
        $highRatio = ($high - $lastClose) / $lastClose * 100;
        $lowRatio = ($low - $lastClose) / $lastClose * 100;

        $hist = $terakhir; // index n-1+h untuk hasil prediksi rekursif
        $result = [];

        for ($h = 1; $h <= $horizon; $h++) {
            $todayIdx = $n - 1 + $h - 1; // "hari itu" sumber fitur (aktual utk h=1)
            $todayClose = $hist[$todayIdx];

            if ($todayClose <= 0) {
                throw new RuntimeException('Peramalan terhenti: nilai prediksi tidak valid.');
            }

            $lag1 = $hist[$todayIdx - 1];
            $lag2 = $hist[$todayIdx - 2];
            $lag3 = $hist[$todayIdx - 3];

            $change = $lag1 > 0 ? ($todayClose - $lag1) / $lag1 * 100 : 0.0;

            $x = [
                $bodyRatio,
                $highRatio,
                $lowRatio,
                $vol,
                $change,
                $lag1 > 0 ? ($lag1 - $todayClose) / $todayClose * 100 : 0.0,
                $lag2 > 0 ? ($lag2 - $todayClose) / $todayClose * 100 : 0.0,
                $lag3 > 0 ? ($lag3 - $todayClose) / $todayClose * 100 : 0.0,
            ];

            $dist = $rf->predictAllTreesOne($x);
            $iv = Quantile::interval($dist, 0.10);

            $q10 = $todayClose * (1 + $iv['q10'] / 100);
            $q50 = $todayClose * (1 + $iv['q50'] / 100);
            $q90 = $todayClose * (1 + $iv['q90'] / 100);

            $hist[$n - 1 + $h] = $q50; // rekursif: dipakai sebagai lag hari berikutnya

            $result[] = [
                'tanggal' => $this->nextTradingDate($lastDate, $h),
                'q10' => $q10,
                'q50' => $q50,
                'q90' => $q90,
            ];
        }

        return $result;
    }

    /**
     * Tambah hari kalender, lompati akhir pekan (meniru hari bursa).
     */
    public function nextTradingDate(string $date, int $days): string
    {
        $dt = new \DateTime($date);
        $count = 0;
        while ($count < $days) {
            $dt->modify('+1 day');
            $dow = (int) $dt->format('N');
            if ($dow <= 5) {
                $count++;
            }
        }

        return $dt->format('Y-m-d');
    }
}
