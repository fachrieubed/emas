<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PrediksiController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/prediksi', [PrediksiController::class, 'index'])->name('prediksi');
Route::view('/metodologi', 'metodologi')->name('metodologi');
Route::get('/api/gold', [DashboardController::class, 'goldData'])->name('api.gold');
Route::get('/training/latest/status', [DashboardController::class, 'trainingLatest'])->name('training.latest');
Route::get('/training/{uploadId}/status', [DashboardController::class, 'trainingStatus'])->name('training.status')->whereNumber('uploadId');

// ---- Autentikasi (login khusus admin) ----
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// ---- Panel admin (harus login) ----
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::post('/upload', [AdminController::class, 'upload'])->name('upload');
    Route::post('/retrain', [AdminController::class, 'retrain'])->name('retrain');
    Route::put('/data/{id}', [AdminController::class, 'update'])->name('update');
    Route::delete('/data/{id}', [AdminController::class, 'destroy'])->name('destroy');
    Route::delete('/data', [AdminController::class, 'destroyAll'])->name('destroy-all');
});