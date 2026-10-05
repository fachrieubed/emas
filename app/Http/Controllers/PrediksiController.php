<?php

namespace App\Http\Controllers;

use App\Models\GoldPrice;
use App\Models\ModelRun;
use App\Models\Upload;

class PrediksiController extends Controller
{
    public function index()
    {
        $modelRun = ModelRun::query()->where('status', 'done')->latest('id')->first();
        $processing = Upload::query()->where('status', 'processing')->latest()->first();

        $history = GoldPrice::query()->orderBy('tanggal')->get(['tanggal', 'terakhir']);

        // harga emas terakhir yang tercatat — dipakai sebagai pembanding di tabel peramalan
        $latestPrice = GoldPrice::query()->orderByDesc('tanggal')->first();

        $testPredictions = $modelRun
            ? $modelRun->predictions()->where('kind', 'test')->orderBy('tanggal')->get()
            : collect();
        $forecastPredictions = $modelRun
            ? $modelRun->predictions()->where('kind', 'forecast')->orderBy('tanggal')->get()
            : collect();

        return view('prediksi', compact(
            'modelRun',
            'processing',
            'history',
            'latestPrice',
            'testPredictions',
            'forecastPredictions'
        ));
    }
}