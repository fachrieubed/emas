<?php

namespace App\Http\Controllers;

use App\Models\GoldPrice;
use App\Models\ModelRun;
use App\Models\Upload;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $latest = GoldPrice::query()->orderByDesc('tanggal')->first();
        $totalData = GoldPrice::query()->count();
        $bucketDate = GoldPrice::query()->min('tanggal');

        $modelRun = ModelRun::query()->where('status', 'done')->latest('id')->first();
        $processing = Upload::query()->where('status', 'processing')->latest()->first();
        $lastUpload = Upload::query()->latest()->first();

        $history = GoldPrice::query()->orderBy('tanggal')->get(['tanggal', 'terakhir']);

        $testPredictions = $modelRun
            ? $modelRun->predictions()->where('kind', 'test')->orderBy('tanggal')->get()
            : collect();
        $forecastPredictions = $modelRun
            ? $modelRun->predictions()->where('kind', 'forecast')->orderBy('tanggal')->get()
            : collect();

        return view('dashboard', compact(
            'latest',
            'totalData',
            'bucketDate',
            'modelRun',
            'processing',
            'lastUpload',
            'history',
            'testPredictions',
            'forecastPredictions'
        ));
    }

    public function trainingStatus(int $uploadId)
    {
        $upload = Upload::find($uploadId);

        if (!$upload) {
            return response()->json(['error' => 'Upload tidak ditemukan.'], 404);
        }

        $modelRun = $upload->modelRuns()->latest('id')->first();

        return response()->json([
            'id' => $upload->id,
            'original_name' => $upload->original_name,
            'status' => $upload->status,
            'message' => $upload->message,
            'error' => $upload->error,
            'total_rows' => $upload->total_rows,
            'parsed_rows' => $upload->parsed_rows,
            'model_status' => $modelRun?->status,
            'model_message' => $modelRun?->message,
            'done' => $upload->status === 'done' || $upload->status === 'failed',
        ]);
    }

    public function trainingLatest()
    {
        $modelRun = ModelRun::query()->latest('id')->first();

        if (!$modelRun) {
            return response()->json([
                'status' => 'done',
                'done' => true,
                'message' => '',
                'model_status' => null,
                'model_message' => '',
            ]);
        }

        return response()->json([
            'id' => $modelRun->id,
            'status' => $modelRun->status,
            'message' => $modelRun->message ?? '',
            'model_status' => $modelRun->status,
            'model_message' => $modelRun->message,
            'done' => $modelRun->status === 'done' || $modelRun->status === 'failed',
        ]);
    }

    public function goldData(Request $request)
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $query = GoldPrice::query()->orderByDesc('tanggal');

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        $data = $query->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
            'total' => $data->total(),
            'per_page' => $data->perPage(),
        ]);
    }
}