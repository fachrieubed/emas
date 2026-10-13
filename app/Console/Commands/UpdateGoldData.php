<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Services\GoldDataSync;
use App\Services\TrainingService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pembaruan data otomatis harian.
 *
 * Mengambil data emas terbaru dari Yahoo Finance (GC=F), menambal tanggal
 * perdagangan yang belum ada (mis. hari Jumat yang sempat hilang), menghapus
 * baris akhir pekan / baris kosong, lalu (opsional) melatih ulang model.
 *
 * Contoh:
 *   php artisan gold:update              # sinkron + latih ulang bila ada data baru
 *   php artisan gold:update --no-retrain # sinkron saja
 */
class UpdateGoldData extends Command
{
    /** Kunci cache untuk menyimpan info sinkronisasi terakhir (dibaca halaman web). */
    private const SYNC_KEY = 'gold.last_sync';

    protected $signature = 'gold:update
        {--days=90 : Rentang hari untuk menyegarkan data terbaru}
        {--no-retrain : Jangan latih ulang model setelah data diperbarui}
        {--n-estimators=500}
        {--min-samples-leaf=5}
        {--min-samples-split=2}
        {--max-features=sqrt}
        {--split=0.8}
        {--horizon=7}
        {--random-state=42}';

    protected $description = 'Ambil data harga emas terbaru otomatis (Yahoo Finance), tambal tanggal kosong, lalu simpan ke database';

    public function handle(GoldDataSync $sync, TrainingService $training): int
    {
        $days = max(3, (int) $this->option('days'));

        $minDate = GoldPrice::query()->weekdaysOnly()->min('tanggal');
        $maxDate = GoldPrice::query()->weekdaysOnly()->max('tanggal');

        $this->info('Sinkronisasi data emas otomatis (sumber: ' . GoldDataSync::SOURCE . ')...');
        if ($minDate) {
            $this->line("  Data saat ini: {$minDate} s/d {$maxDate}");
        }

        try {
            $result = $sync->sync($days);
        } catch (Exception $e) {
            $this->error($e->getMessage());
            Log::error('gold:update gagal mengambil data: ' . $e->getMessage());
            $this->recordSync([
                'status' => 'error',
                'source' => GoldDataSync::SOURCE,
                'message' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $inserted = (int) $result['inserted'];
        $updated = (int) $result['updated'];
        $kept = (int) $result['kept'];
        $deletedWeekend = (int) $result['deleted_weekend'];
        $deletedEmpty = (int) $result['deleted_empty'];
        $changed = (int) $result['changed'];

        $this->info("Sinkronisasi selesai: {$inserted} baris baru, {$updated} diperbarui, {$kept} lama dipertahankan.");
        if ($deletedWeekend || $deletedEmpty) {
            $this->line("  Dibersihkan: {$deletedWeekend} baris weekend, {$deletedEmpty} baris kosong.");
        }
        if ($changed) {
            $this->line("  Perubahan% dihitung ulang untuk {$changed} baris.");
        }

        Log::info('gold:update selesai', compact('inserted', 'updated', 'kept', 'deletedWeekend', 'deletedEmpty', 'changed'));

        $this->recordSync([
            'status' => 'ok',
            'source' => GoldDataSync::SOURCE,
            'inserted' => $inserted,
            'updated' => $updated,
            'kept' => $kept,
            'deleted_weekend' => $deletedWeekend,
            'deleted_empty' => $deletedEmpty,
            'changed' => $changed,
            'total' => (int) $result['total'],
            'latest_date' => $result['latest_date'],
            'retrain' => $this->option('no-retrain') ? 'skipped' : 'pending',
            'message' => null,
        ]);

        if (!$this->option('no-retrain') && ($inserted + $updated) > 0) {
            $this->info('Melatih ulang model tsQRF dengan data terbaru...');

            try {
                $modelRun = $training->retrain([
                    'n_estimators' => (int) $this->option('n-estimators'),
                    'min_samples_leaf' => (int) $this->option('min-samples-leaf'),
                    'min_samples_split' => (int) $this->option('min-samples-split'),
                    'max_features' => (string) $this->option('max-features'),
                    'split_ratio' => (float) $this->option('split'),
                    'horizon' => (int) $this->option('horizon'),
                    'random_state' => (int) $this->option('random-state'),
                ]);

                $this->info("Model diperbarui (model run #{$modelRun->id}, status {$modelRun->status}).");
                $this->recordSync(['retrain' => 'ok', 'model_run_id' => $modelRun->id]);
            } catch (Exception $e) {
                $this->error('Data tersimpan, tetapi pelatihan ulang gagal: ' . $e->getMessage());
                $this->recordSync(['retrain' => 'failed', 'message' => 'Pelatihan ulang gagal: ' . $e->getMessage()]);

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Simpan ringkasan sinkronisasi terakhir ke cache agar bisa ditampilkan di web
     * ("terakhir sinkron otomatis: ...").
     */
    private function recordSync(array $data): void
    {
        try {
            $current = Cache::get(self::SYNC_KEY, []);

            Cache::forever(self::SYNC_KEY, array_merge(
                is_array($current) ? $current : [],
                ['at' => now()->toIso8601String()],
                $data
            ));
        } catch (\Throwable $e) {
            // Gagal menyimpan info tidak boleh menggagalkan proses sinkronisasi.
            Log::warning('Gagal menyimpan info sinkronisasi: ' . $e->getMessage());
        }
    }
}