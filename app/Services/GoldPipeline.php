<?php

namespace App\Services;

use App\Services\ML\Quantile;
use App\Services\ML\RandomForest;
use RuntimeException;

/**
 * Pipeline model tsQRF: preprocessing -> train/test split (80/20 berurutan)
 * -> latih Random Forest -> prediksi quantile Q10/Q50/Q90 -> evaluasi
 * -> peramalan (forecast) N hari ke depan secara rekursif.
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
        $testDates = array_values(array_slice($dates, $split));

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

        // --- Prediksi quantile pada data testing ---
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
            $actual = $yTest[$i];

            $err = $actual - $iv['q50'];
            $mae += abs($err);
            $rmse += $err * $err;
            if ($actual != 0.0) {
                $mape += abs($err / $actual);
            }
            if ($actual >= $iv['q10'] && $actual <= $iv['q90']) {
                $covered++;
            }
            $wSum += $iv['width'];

            $testRows[] = [
                'tanggal' => $testDates[$i],
                'actual' => $actual,
                'q10' => $iv['q10'],
                'q50' => $iv['q50'],
                'q90' => $iv['q90'],
            ];
        }

        $metrics = [
            'mae' => $nTest > 0 ? $mae / $nTest : 0.0,
            'rmse' => $nTest > 0 ? sqrt($rmse / $nTest) : 0.0,
            'mape' => $nTest > 0 ? ($mape / $nTest) * 100 : 0.0,
            'coverage' => $nTest > 0 ? ($covered / $nTest) * 100 : 0.0,
            'avg_interval_width' => $nTest > 0 ? $wSum / $nTest : 0.0,
        ];

        // --- Forecast N hari ke depan (rekursif) ---
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
        ];
    }

    /**
     * Peramalan rekursif: target = Terakhir periode berikutnya (shift -1),
     * fitur OHLC/Volume/Perubahan% hari depan diasumsikan sama dengan observasi terakhir.
     *
     * @param array<int, array<string, mixed>> $rows baris bersih (sudah urut)
     * @param array<int, float> $terakhir
     * @return array<int, array{tanggal:string, q10:float, q50:float, q90:float}>
     */
    private function forecast(RandomForest $rf, array $rows, array $terakhir, int $horizon): array
    {
        $n = count($terakhir);
        $lastQuote = $rows[$n - 1];

        $open = (float) $lastQuote['pembukaan'];
        $high = (float) $lastQuote['tertinggi'];
        $low = (float) $lastQuote['terendah'];
        $vol = isset($lastQuote['volume']) && $lastQuote['volume'] !== null ? (float) $lastQuote['volume'] : 0.0;
        $pct = isset($lastQuote['perubahan']) && $lastQuote['perubahan'] !== null ? (float) $lastQuote['perubahan'] : 0.0;
        $lastDate = $lastQuote['tanggal'];

        $hist = $terakhir; // index virtual n-1+h dst. untuk hasil prediksi
        $result = [];

        for ($h = 1; $h <= $horizon; $h++) {
            $targetIdx = $n - 1 + $h; // index harga yang diprediksi

            $lag1 = $hist[$targetIdx - 2];
            $lag2 = $hist[$targetIdx - 3];
            $lag3 = $hist[$targetIdx - 4];

            $x = [$open, $high, $low, $vol, $pct, $lag1, $lag2, $lag3];

            $dist = $rf->predictAllTreesOne($x);
            $iv = Quantile::interval($dist, 0.10);
            $hist[$targetIdx] = $iv['q50'];

            $result[] = [
                'tanggal' => $this->nextTradingDate($lastDate, $h),
                'q10' => $iv['q10'],
                'q50' => $iv['q50'],
                'q90' => $iv['q90'],
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