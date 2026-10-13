<?php

namespace App\Services;

use App\Models\GoldPrice;
use App\Models\ModelRun;
use App\Models\Prediction;
use App\Models\Upload;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Menjalankan pipeline tsQRF untuk sebuah dataset yang di-upload:
 * parse CSV -> insert data emas -> preprocessing -> train/test -> forecast -> persist.
 * Juga mendukung "retrain" langsung dari data yang sudah ada di database.
 */
class TrainingService
{
    public const MODEL_DIR = 'models';

    public function __construct(
        protected CsvParser $parser,
        protected GoldPreprocessor $preprocessor,
        protected GoldPipeline $pipeline,
    ) {
    }

    /**
     * @param array $params lihat ProcessTrainingCommand / AdminController
     */
    public function run(Upload $upload, array $params = []): ModelRun
    {
        $modelRun = $this->createModelRun($params, $upload->id);

        try {
            $upload->update(['status' => 'processing', 'message' => 'Membaca file CSV...', 'error' => null]);

            $progress = function (string $msg) use (&$modelRun, $upload) {
                $modelRun->update(['message' => $msg]);
                $upload->update(['message' => $msg]);
                echo '  * ' . $msg . PHP_EOL;
            };

            // 1. Parse file
            $parsed = $this->parser->parse($upload->stored_path);
            $rows = $parsed['rows'];

            // 2. Simpan data emas (dataset aktif diganti dengan data terbaru)
            $progress('Memasukkan ' . count($rows) . ' baris data emas ke database...');
            DB::transaction(function () use ($rows, $upload, $parsed) {
                GoldPrice::query()->delete();
                $chunks = array_chunk($rows, 500);
                foreach ($chunks as $chunk) {
                    $payload = array_map(fn ($r) => [
                        'tanggal' => $r['tanggal'],
                        'terakhir' => $r['terakhir'],
                        'pembukaan' => $r['pembukaan'],
                        'tertinggi' => $r['tertinggi'],
                        'terendah' => $r['terendah'],
                        'volume' => $r['volume'],
                        'perubahan' => $r['perubahan'],
                        'upload_id' => $upload->id,
                    ], $chunk);
                    GoldPrice::insert($payload);
                }
            });

            $totalRows = count($rows);
            $minDate = $rows ? min(array_column($rows, 'tanggal')) : null;
            $maxDate = $rows ? max(array_column($rows, 'tanggal')) : null;
            $upload->update([
                'status' => 'processing',
                'total_rows' => $parsed['total_rows'],
                'parsed_rows' => $totalRows,
                'message' => "{$totalRows} baris data valid diproses (weekend di-drop: {$parsed['dropped_weekend']}, tidak lengkap: {$parsed['dropped_incomplete']}).",
            ]);

            // 3-7. Preprocessing, training, evaluasi, persisten
            $this->trainAndPersist($modelRun, $rows, $params, $progress);

            $upload->update([
                'status' => 'done',
                'message' => "Selesai: {$totalRows} baris ({$minDate} s.d. {$maxDate}), model tsQRF dengan {$modelRun->n_estimators} pohon.",
            ]);

            return $modelRun->refresh();
        } catch (Exception $e) {
            Log::error('TrainingService gagal: ' . $e->getMessage(), ['upload_id' => $upload->id, 'trace' => $e->getTraceAsString()]);

            $modelRun->update(['status' => 'failed', 'error' => $e->getMessage(), 'message' => 'Gagal.' . ($e instanceof RuntimeException ? ' ' . $e->getMessage() : '')]);
            $upload->update(['status' => 'failed', 'error' => $e->getMessage(), 'message' => 'Gagal memproses. ' . $e->getMessage()]);

            echo 'ERROR: ' . $e->getMessage() . PHP_EOL;

            throw $e;
        }
    }

    /**
     * Melatih ulang model dari seluruh data emas yang sudah ada di database
     * (dipakai setelah admin mengedit/menghapus data).
     *
     * @param array $params konfigurasi model (sama seperti run)
     */
    public function retrain(array $params = []): ModelRun
    {
        $modelRun = $this->createModelRun($params, null);

        try {
            $progress = function (string $msg) use (&$modelRun) {
                $modelRun->update(['message' => $msg]);
                echo '  * ' . $msg . PHP_EOL;
            };

            $rows = GoldPrice::query()->weekdaysOnly()->orderBy('tanggal')->get()->map(fn ($g) => [
                'tanggal' => $g->tanggal->format('Y-m-d'),
                'terakhir' => $g->terakhir,
                'pembukaan' => $g->pembukaan,
                'tertinggi' => $g->tertinggi,
                'terendah' => $g->terendah,
                'volume' => $g->volume,
                'perubahan' => $g->perubahan,
            ])->values()->all();

            if (count($rows) === 0) {
                throw new RuntimeException('Tidak ada data emas di database untuk dilatih ulang.');
            }

            $progress("Melatih ulang model dari {$modelRun->n_estimators} Tree menggunakan " . count($rows) . ' baris data...');

            $this->trainAndPersist($modelRun, $rows, $params, $progress);

            return $modelRun->refresh();
        } catch (Exception $e) {
            Log::error('TrainingService retrain gagal: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            $modelRun->update(['status' => 'failed', 'error' => $e->getMessage(), 'message' => 'Gagal.' . ($e instanceof RuntimeException ? ' ' . $e->getMessage() : '')]);

            echo 'ERROR: ' . $e->getMessage() . PHP_EOL;

            throw $e;
        }
    }

    /**
     * Preprocessing + training + evaluasi + forecast + persisten ke database.
     *
     * @param array<int, array<string, mixed>> $rows baris data (hasil parser / dari DB)
     */
    private function trainAndPersist(
        ModelRun $modelRun,
        array $rows,
        array $params,
        callable $progress,
    ): ModelRun {
        // 3. Preprocessing + feature engineering (fitur & target berupa rasio %)
        $progress('Membangun fitur relatif (OHLC/Perubahan/Lag_1..3 terhadap penutupan) dan target return...');
        $pre = $this->preprocessor->build($rows);

        // 4. Training + evaluasi + forecast
        $progress('Melatih model Random Forest (tsQRF)...');
        $result = $this->pipeline->run($pre, $params, $progress);

        $metrics = $result['metrics'];
        $model = $result['model'];

        // 5. Simpan model ter-serialize
        $modelPath = self::MODEL_DIR . '/rf_run_' . $modelRun->id . '.tsqrf';
        File::ensureDirectoryExists(storage_path('app/' . self::MODEL_DIR));
        File::put(storage_path('app/' . $modelPath), $model->serialize());

        // 6. Simpan evaluasi (periode dinilai pada tanggal target tiap sampel)
        $sampleDates = $pre['sampleDates'] ?? $pre['dates'];
        $trainStart = $pre['dates'][0] ?? null;
        $trainEnd = $sampleDates[$result['split'] - 1] ?? null;
        $testStart = $sampleDates[$result['split']] ?? null;
        $testEnd = $sampleDates[count($sampleDates) - 1] ?? null;

        $modelRun->update([
            'status' => 'done',
            'message' => 'Model selesai dilatih dan dievaluasi.',
            'error' => null,
            'total_rows' => count($rows),
            'train_rows' => $result['train_rows'],
            'test_rows' => $result['test_rows'],
            'train_start' => $trainStart,
            'train_end' => $trainEnd,
            'test_start' => $testStart,
            'test_end' => $testEnd,
            'mae' => $metrics['mae'],
            'rmse' => $metrics['rmse'],
            'mape' => $metrics['mape'],
            'coverage' => $metrics['coverage'],
            'avg_interval_width' => $metrics['avg_interval_width'],
            'training_seconds' => $result['training_seconds'],
            'model_path' => $modelPath,
        ]);

        // 7. Simpan prediksi (test + forecast)
        $payload = [];
        foreach ($result['test'] as $t) {
            $payload[] = [
                'model_run_id' => $modelRun->id,
                'kind' => 'test',
                'tanggal' => $t['tanggal'],
                'actual' => $t['actual'],
                'q10' => $t['q10'],
                'q50' => $t['q50'],
                'q90' => $t['q90'],
            ];
        }
        foreach ($result['forecast'] as $f) {
            $payload[] = [
                'model_run_id' => $modelRun->id,
                'kind' => 'forecast',
                'tanggal' => $f['tanggal'],
                'actual' => null,
                'q10' => $f['q10'],
                'q50' => $f['q50'],
                'q90' => $f['q90'],
            ];
        }
        foreach (array_chunk($payload, 500) as $chunk) {
            Prediction::insert($chunk);
        }

        $progress('Selesai. MAE=' . round($metrics['mae'], 2)
            . ', RMSE=' . round($metrics['rmse'], 2)
            . ', Coverage=' . round($metrics['coverage'], 2) . '%.');

        return $modelRun->refresh();
    }

    private function createModelRun(array $params, ?int $uploadId): ModelRun
    {
        return ModelRun::create([
            'upload_id' => $uploadId,
            'status' => 'processing',
            'message' => 'Menyiapkan model...',
            'n_estimators' => (int) ($params['n_estimators'] ?? 500),
            'min_samples_leaf' => (int) ($params['min_samples_leaf'] ?? 5),
            'min_samples_split' => (int) ($params['min_samples_split'] ?? 2),
            'max_features' => (string) ($params['max_features'] ?? 'sqrt'),
            'random_state' => (int) ($params['random_state'] ?? 42),
            'split_ratio' => (float) ($params['split_ratio'] ?? 0.80),
            'horizon' => (int) ($params['horizon'] ?? 7),
        ]);
    }
}