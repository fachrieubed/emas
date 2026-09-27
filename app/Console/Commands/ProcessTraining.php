<?php

namespace App\Console\Commands;

use App\Models\Upload;
use App\Services\TrainingService;
use Exception;
use Illuminate\Console\Command;

class ProcessTraining extends Command
{
    protected $signature = 'gold:process {uploadId} {--n-estimators=500} {--min-samples-leaf=5} {--min-samples-split=2} {--max-features=sqrt} {--split=0.8} {--horizon=7} {--random-state=42}';

    protected $description = 'Jalankan pipeline tsQRF untuk dataset yang telah di-upload';

    public function handle(TrainingService $training): int
    {
        $uploadId = (int) $this->argument('uploadId');
        $upload = Upload::find($uploadId);

        if (!$upload) {
            $this->error("Upload #{$uploadId} tidak ditemukan.");

            return self::FAILURE;
        }

        $this->info("Memproses upload #{$uploadId} ({$upload->original_name})...");

        try {
            $modelRun = $training->run($upload, [
                'n_estimators' => (int) $this->option('n-estimators'),
                'min_samples_leaf' => (int) $this->option('min-samples-leaf'),
                'min_samples_split' => (int) $this->option('min-samples-split'),
                'max_features' => (string) $this->option('max-features'),
                'split_ratio' => (float) $this->option('split'),
                'horizon' => (int) $this->option('horizon'),
                'random_state' => (int) $this->option('random-state'),
            ]);

            $this->info("Selesai. Model run #{$modelRun->id} status: {$modelRun->status}");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}