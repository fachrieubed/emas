<?php

namespace App\Http\Controllers;

use App\Models\GoldPrice;
use App\Models\ModelRun;
use App\Models\Prediction;
use App\Models\Upload;
use App\Support\BackgroundProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));

        $query = GoldPrice::query()->weekdaysOnly()->orderByDesc('tanggal');

        if ($search = trim((string) $request->input('search'))) {
            $query->search($search);
        }

        $data = $query->paginate($perPage)->withQueryString();

        $modelRun = ModelRun::query()->where('status', 'done')->latest('id')->first();
        $processing = Upload::query()->where('status', 'processing')->latest()->first();
        $lastSync = Cache::get('gold.last_sync');

        return view('admin.index', compact('data', 'modelRun', 'processing', 'lastSync'));
    }

    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) {
                        $fail('Format file harus .csv, .txt, atau .tsv');
                    }
                },
            ],
            'n_estimators' => 'required|integer|between:10,1000',
            'min_samples_leaf' => 'required|integer|between:1,50',
            'split' => 'required|numeric|between:0.5,0.95',
            'horizon' => 'required|integer|between:1,30',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $file = $request->file('file');
        $name = date('YmdHis') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $path = Storage::putFileAs('uploads', $file, $name);

        $upload = Upload::create([
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => Storage::path($path),
            'status' => 'processing',
            'message' => 'Menunggu proses parsing...',
        ]);

        $spawned = BackgroundProcess::spawnPhp([
            'gold:process',
            (string) $upload->id,
            '--n-estimators=' . (int) $request->input('n_estimators', 500),
            '--min-samples-leaf=' . (int) $request->input('min_samples_leaf', 5),
            '--split=' . (float) $request->input('split', 0.8),
            '--horizon=' . (int) $request->input('horizon', 7),
        ]);

        return back()->with([
            'uploadStarted' => true,
            'uploadId' => $upload->id,
            'spawned' => $spawned,
        ]);
    }

    public function retrain(Request $request)
    {
        $spawned = BackgroundProcess::spawnPhp([
            'gold:retrain',
            '--n-estimators=' . (int) ($request->input('n_estimators', 500)),
            '--min-samples-leaf=' . (int) ($request->input('min_samples_leaf', 5)),
            '--split=' . (float) ($request->input('split', 0.8)),
            '--horizon=' . (int) ($request->input('horizon', 7)),
        ]);

        return back()->with([
            'retrainStarted' => true,
            'spawned' => $spawned,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $gold = GoldPrice::findOrFail($id);

        $validated = $request->validate([
            'tanggal' => 'required|date|unique:gold_prices,tanggal,' . $gold->id,
            'terakhir' => 'required|numeric',
            'pembukaan' => 'required|numeric',
            'tertinggi' => 'required|numeric',
            'terendah' => 'required|numeric',
            'volume' => 'required|numeric',
            'perubahan' => 'required|numeric',
        ]);

        $gold->update([
            'tanggal' => $validated['tanggal'],
            'terakhir' => self::toNumber($validated['terakhir']),
            'pembukaan' => self::toNumber($validated['pembukaan']),
            'tertinggi' => self::toNumber($validated['tertinggi']),
            'terendah' => self::toNumber($validated['terendah']),
            'volume' => $validated['volume'] === null || $validated['volume'] === '' ? null : self::toNumber($validated['volume']),
            'perubahan' => $validated['perubahan'] === null || $validated['perubahan'] === '' ? null : self::toNumber($validated['perubahan']),
        ]);

        return back()->with('success', "Data tanggal {$gold->tanggal->format('d/m/Y')} berhasil diperbarui. Jangan lupa klik \"Latih Ulang\" agar prediksi ikut diperbarui.");
    }

    public function destroy(Request $request, int $id)
    {
        $gold = GoldPrice::findOrFail($id);
        $tanggal = $gold->tanggal->format('d/m/Y');
        $gold->delete();

        return back()->with('success', "Data tanggal {$tanggal} berhasil dihapus.");
    }

    public function destroyAll(Request $request)
    {
        DB::transaction(function () {
            Prediction::query()->delete();
            ModelRun::query()->delete();
            GoldPrice::query()->delete();
        });

        // hapus file model ter-serialize yang sudah tidak terpakai
        $pattern = storage_path('app/models/*.tsqrf');
        foreach (glob($pattern) ?: [] as $file) {
            File::delete($file);
        }

        return back()->with('success', 'Semua data emas, hasil model, dan prediksi telah dihapus. Upload file baru untuk memulai lagi.');
    }

    private static function toNumber(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        // dukung format Indonesia: 1.883.920,75
        $clean = str_replace('.', '', (string) $value);

        return (float) str_replace(',', '.', $clean);
    }
}