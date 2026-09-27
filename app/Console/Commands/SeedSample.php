<?php

namespace App\Console\Commands;

use App\Models\Upload;
use App\Services\TrainingService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SeedSample extends Command
{
    protected $signature = 'gold:seed-sample {--file=} {--n-estimators=500} {--min-samples-leaf=5} {--split=0.8} {--horizon=7} {--max-features=sqrt}';

    protected $description = 'Import dataset contoh lalu jalankan pelatihan model tsQRF';

    public function handle(TrainingService $training): int
    {
        $file = $this->option('file') ?: storage_path('app/samples/harga_emas_contoh.csv');

        if (!File::exists($file)) {
            $this->error("File contoh tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        // salin ke folder uploads agar konsisten dengan alur upload
        $dir = storage_path('app/uploads');
        File::ensureDirectoryExists($dir);
        $dest = $dir . '/' . date('YmdHis') . '_' . basename($file);
        File::copy($file, $dest);

        $upload = Upload::create([
            'original_name' => 'contoh_' . basename($file),
            'stored_path' => $dest,
            'status' => 'processing',
            'message' => 'Memulai import dataset contoh...',
        ]);

        $this->info("Import dataset contoh: {$file}");

        try {
            $modelRun = $training->run($upload, [
                'n_estimators' => (int) $this->option('n-estimators'),
                'min_samples_leaf' => (int) $this->option('min-samples-leaf'),
                'max_features' => (string) $this->option('max-features'),
                'split_ratio' => (float) $this->option('split'),
                'horizon' => (int) $this->option('horizon'),
            ]);
            $this->info('Selesai. Model run #' . $modelRun->id . ' berhasil dibuat.');

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}