<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Services\GoldDataFetcher;
use App\Services\TrainingService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

    public function handle(GoldDataFetcher $fetcher, TrainingService $training): int
    {
        $days = max(3, (int) $this->option('days'));

        $minDate = GoldPrice::query()->weekdaysOnly()->min('tanggal');
        $maxDate = GoldPrice::query()->weekdaysOnly()->max('tanggal');

        $this->info('Sinkronisasi data emas otomatis (sumber: Yahoo Finance GC=F)...');
        if ($minDate) {
            $this->line("  Data saat ini: {$minDate} s/d {$maxDate}");
        }

        try {
            $rows = $minDate ? $fetcher->fetchSince($minDate) : $fetcher->fetch($days);
        } catch (Exception $e) {
            $this->error($e->getMessage());
            Log::error('gold:update gagal mengambil data: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (count($rows) === 0) {
            $this->warn('Tidak ada data yang bisa diambil dari sumber.');

            return self::SUCCESS;
        }

        // Jangan menambah data sebelum tanggal awal dataset.
        if ($minDate) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['tanggal'] >= $minDate));
        }

        $existing = GoldPrice::query()->pluck('tanggal')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10))
            ->flip();

        $recentCutoff = (new \DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d');

        $inserted = 0;
        $updated = 0;
        $kept = 0;

        DB::transaction(function () use ($rows, $existing, $recentCutoff, &$inserted, &$updated, &$kept) {
            foreach ($rows as $row) {
                $date = $row['tanggal'];
                $payload = [
                    'tanggal' => $date,
                    'pembukaan' => $row['pembukaan'],
                    'tertinggi' => $row['tertinggi'],
                    'terendah' => $row['terendah'],
                    'terakhir' => $row['terakhir'],
                    'volume' => $row['volume'],
                ];

                if (!$existing->has($date)) {
                    $payload['perubahan'] = 0; // diisi pada tahap hitung ulang
                    GoldPrice::create($payload);
                    $inserted++;
                } elseif ($date >= $recentCutoff) {
                    // segarkan data terbaru agar bila ada nilai janggal ikut diperbaiki
                    GoldPrice::query()->where('tanggal', $date)->update($payload);
                    $updated++;
                } else {
                    $kept++; // data lama sudah benar & cocok dengan sumber
                }
            }
        });

        // Kebijakan "tidak boleh ada data kosong": buang baris weekend & baris berkolom kosong.
        $deletedWeekend = GoldPrice::query()->whereRaw('DAYOFWEEK(tanggal) IN (1, 7)')->delete();
        $deletedEmpty = GoldPrice::query()
            ->where(function ($q) {
                $q->whereNull('terakhir')->orWhereNull('pembukaan')->orWhereNull('tertinggi')
                    ->orWhereNull('terendah')->orWhereNull('volume')->orWhereNull('perubahan');
            })
            ->delete();

        // Hitung ulang Perubahan% mengikuti urutan hari perdagangan (setelah tanggal ditambal).
        $changed = $this->recomputeChange();

        $this->info("Sinkronisasi selesai: {$inserted} baris baru, {$updated} diperbarui, {$kept} lama dipertahankan.");
        if ($deletedWeekend || $deletedEmpty) {
            $this->line("  Dibersihkan: {$deletedWeekend} baris weekend, {$deletedEmpty} baris kosong.");
        }
        if ($changed) {
            $this->line("  Perubahan% dihitung ulang untuk {$changed} baris.");
        }

        Log::info('gold:update selesai', compact('inserted', 'updated', 'kept', 'deletedWeekend', 'deletedEmpty', 'changed'));

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
            } catch (Exception $e) {
                $this->error('Data tersimpan, tetapi pelatihan ulang gagal: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Hitung ulang kolom Perubahan% = (penutupan_t - penutupan_{t-1}) / penutupan_{t-1} x 100
     * berdasarkan urutan hari perdagangan yang sudah bersih.
     */
    private function recomputeChange(): int
    {
        $rows = GoldPrice::query()->weekdaysOnly()->orderBy('tanggal')->get(['id', 'terakhir', 'perubahan']);
        $prev = null;
        $changed = 0;

        DB::transaction(function () use ($rows, &$prev, &$changed) {
            foreach ($rows as $g) {
                $close = (float) $g->terakhir;
                $chg = ($prev !== null && $prev != 0.0)
                    ? round(($close - $prev) / $prev * 100, 2)
                    : ($g->perubahan !== null ? (float) $g->perubahan : 0.0);

                if ((float) $g->perubahan !== (float) $chg) {
                    GoldPrice::query()->whereKey($g->id)->update(['perubahan' => $chg]);
                    $changed++;
                }

                $prev = $close;
            }
        });

        return $changed;
    }
}
