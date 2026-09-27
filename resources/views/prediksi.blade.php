@extends('layouts.app')

@section('title', 'Prediksi — Prediksi Harga Emas')

@section('content')
@php
    $fmt = fn ($v, $dec = 0) => number_format((float) $v, $dec, ',', '.');
    $model = $modelRun;
    $next = $forecastPredictions->first();
    $historyLabels = $history->map(fn ($h) => $h->tanggal->format('d/m/Y'));
    $historyValues = $history->map(fn ($h) => (float) $h->terakhir);

    // evaluasi testing — ambil 90 titik terakhir agar grafik mudah dibaca
    $testSlice = $testPredictions->slice(-90)->values();
    $testLabels = $testSlice->map(fn ($p) => $p->tanggal->format('d/m/Y'));
    $testActual = $testSlice->map(fn ($p) => (float) $p->actual);
    $testQ10 = $testSlice->map(fn ($p) => (float) $p->q10);
    $testQ50 = $testSlice->map(fn ($p) => (float) $p->q50);
    $testQ90 = $testSlice->map(fn ($p) => (float) $p->q90);

    // forecast — 30 hari historis terakhir + peramalan
    $histTail = $history->slice(-35)->values();
    $fcLabels = $histTail->map(fn ($h) => $h->tanggal->format('d/m/Y'))
        ->concat($forecastPredictions->map(fn ($p) => $p->tanggal->format('d/m/Y')));
    $fcActual = $histTail->map(fn ($h) => (float) $h->terakhir)->concat(array_fill(0, $forecastPredictions->count(), null));
    $fcQ10 = array_merge(array_fill(0, $histTail->count(), null), $forecastPredictions->map(fn ($p) => (float) $p->q10)->all());
    $fcQ50 = array_merge(array_fill(0, $histTail->count(), null), $forecastPredictions->map(fn ($p) => (float) $p->q50)->all());
    $fcQ90 = array_merge(array_fill(0, $histTail->count(), null), $forecastPredictions->map(fn ($p) => (float) $p->q90)->all());
@endphp

@if ($processing)
<div id="uploadData" data-upload-id="{{ $processing->id }}" data-upload-status="{{ $processing->status }}"></div>
@endif

{{-- ===================== BREADCRUMB / NAVIGASI ===================== --}}
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item">
                <a href="{{ route('dashboard') }}" class="text-decoration-none">
                    <i class="bi bi-speedometer2 me-1"></i>Dashboard
                </a>
            </li>
            <li class="breadcrumb-item active fw-semibold" aria-current="page">
                <i class="bi bi-graph-up-arrow me-1 text-gold"></i>Prediksi
            </li>
        </ol>
    </nav>
    <a href="{{ auth()->check() ? route('admin.index') : route('login') }}" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-shield-lock me-1"></i>Kelola Data (Admin)
    </a>
</div>

{{-- ===================== PREDIKSI HARGA (RENTANG) ===================== --}}
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="range-box p-4 h-100 d-flex flex-column justify-content-center">
            @if ($model && $next)
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge badge-gold"><i class="bi bi-graph-up-arrow me-1"></i>Prediksi harga {{ $next->tanggal->format('d/m/Y') }}</span>
                    @if ($next->q90 >= 0)
                    <span class="text-muted small">Rentang 80% (Q10–Q90)</span>
                    @endif
                </div>
                <div class="d-flex flex-wrap align-items-end gap-3">
                    <div>
                        <div class="fs-range">
                            {{ $fmt($next->q10, 0) }}
                            <span class="opacity-60 px-1">–</span>
                            {{ $fmt($next->q90, 0) }}
                        </div>
                        <div class="stat-label text-muted">Nilai tengah (Q50): <b class="text-dark">{{ $fmt($next->q50, 2) }}</b></div>
                    </div>
                </div>
            @else
                <h4 class="text-gold mb-2"><i class="bi bi-bullseye me-2"></i>Belum ada hasil prediksi</h4>
                <p class="mb-3 text-muted">Unggah data emas (CSV) lalu model tsQRF akan melatih <b>500 pohon</b> dan menghasilkan
                    rentang prediksi <b>Q10–Q90</b> serta nilai tengah <b>Q50</b>.</p>
                <div>
                    <a href="{{ auth()->check() ? route('admin.index') : route('login') }}" class="btn btn-gold">
                        <i class="bi bi-cloud-upload me-1"></i>Upload Data Emas (Admin)
                    </a>
                </div>
            @endif
        </div>
    </div>
    <div class="col-lg-5 d-flex flex-column gap-3">
        <div class="card flex-fill">
            <div class="card-body py-3 d-flex flex-column justify-content-center">
                <div class="stat-label">Periode Peramalan</div>
                <div class="stat-value mt-1">{{ $model && $forecastPredictions->count() ? $forecastPredictions->first()->tanggal->format('d/m/Y') . ' – ' . $forecastPredictions->last()->tanggal->format('d/m/Y') : '-' }}</div>
                <small class="text-muted">{{ $model ? $forecastPredictions->count() . ' hari ke depan (recursive)' : 'belum ada model' }}</small>
            </div>
        </div>
        <div class="card flex-fill">
            <div class="card-body py-3 d-flex flex-column justify-content-center">
                <div class="stat-label">Sumber Data</div>
                <div class="stat-value mt-1 small lh-base">{{ $model ? number_format((float) $model->train_rows, 0, ',', '.') . ' train + ' . number_format((float) $model->test_rows, 0, ',', '.') . ' test' : '-' }}</div>
                <small class="text-muted">{{ $model ? 'split ' . number_format((float) $model->split_ratio * 100, 0, ',', '.') . '% : ' . number_format((1 - (float) $model->split_ratio) * 100, 0, ',', '.') . '%' : 'belum ada model' }}</small>
            </div>
        </div>
    </div>
</div>

@if ($model)
{{-- ===================== METRIK MODEL ===================== --}}
<div class="row g-3 mb-4">
    @foreach ([
        ['MAE', number_format((float) $model->mae, 0, ',', '.')],
        ['RMSE', number_format((float) $model->rmse, 0, ',', '.')],
        ['MAPE', number_format((float) $model->mape, 2, ',', '.') . '%'],
        ['Coverage Q10–Q90', number_format((float) $model->coverage, 2, ',', '.') . '%'],
        ['Lebar Interval Rata²', number_format((float) $model->avg_interval_width, 0, ',', '.')],
    ] as $m)
    <div class="col-6 col-md-4 col-xl">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="stat-label">{{ $m[0] }}</div>
                <div class="stat-value text-gold mt-1">{{ $m[1] }}</div>
            </div>
        </div>
    </div>
    @endforeach
    <div class="col-12 col-md-6 col-xl">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="stat-label">Periode Uji (Testing)</div>
                <div class="stat-value mt-1 small lh-base">{{ optional($model->test_start)->format('d/m/Y') }} – {{ optional($model->test_end)->format('d/m/Y') }}</div>
                <small class="text-muted">{{ $model->train_rows }} train · {{ $model->test_rows }} test · {{ number_format((float) $model->training_seconds, 1, ',', '.') }} dtk latih</small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="stat-label">Konfigurasi Model tsQRF</div>
                <div class="stat-value mt-1 small lh-base">{{ $model->n_estimators }} pohon · min_leaf {{ $model->min_samples_leaf }} · {{ $model->max_features }}</div>
                <small class="text-muted">split {{ number_format((float) $model->split_ratio * 100, 0, ',', '.') }}% · seed {{ $model->random_state }} · horizon {{ $model->horizon }} hari</small>
            </div>
        </div>
    </div>
</div>

{{-- ===================== GRAFIK ===================== --}}
<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="section-title mb-3"><i class="bi bi-activity text-gold me-2"></i>Harga Emas Historis</h6>
                <canvas id="chartHistory" height="150"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="section-title mb-1"><i class="bi bi-clipboard-data text-gold me-2"></i>Evaluasi Model pada Data Testing</h6>
                <small class="text-muted d-block mb-2">Garis AQ (Aktual) vs Q50, area kuning = interval prediksi Q10–Q90</small>
                <canvas id="chartTest" height="150"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h6 class="section-title mb-1"><i class="bi bi-diagram-3 text-gold me-2"></i>Peramalan {{ $forecastPredictions->count() }} Hari ke Depan</h6>
                <small class="text-muted d-block mb-2">Garis putus-putus = nilai tengah prediksi (Q50), area kuning = rentang Q10–Q90</small>
                <canvas id="chartForecast" height="120"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- ===================== TABEL PERAMALAN ===================== --}}
<div class="card mb-4">
    <div class="card-header bg-transparent">
        <h6 class="section-title mb-0"><i class="bi bi-table text-gold me-2"></i>Hasil Peramalan Harga Emas</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-data table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th class="text-end">Batas Bawah (Q10)</th>
                    <th class="text-end">Nilai Tengah (Q50)</th>
                    <th class="text-end">Batas Atas (Q90)</th>
                    <th class="text-end">Rentang</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($forecastPredictions as $fp)
                <tr>
                    <td class="fw-semibold">{{ $fp->tanggal->format('d/m/Y') }}</td>
                    <td class="text-end">{{ $fmt($fp->q10, 0) }}</td>
                    <td class="text-end fw-bold">{{ $fmt($fp->q50, 2) }}</td>
                    <td class="text-end">{{ $fmt($fp->q90, 0) }}</td>
                    <td class="text-end text-gold">{{ $fmt($fp->q90 - $fp->q10, 0) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@else
<div class="alert alert-light border d-flex gap-3">
    <i class="bi bi-lightbulb fs-4 text-gold"></i>
    <div>
        <b>Belum ada model.</b> Upload file CSV data emas untuk melatih model, atau jalankan data contoh:
        <code>php artisan gold:seed-sample</code>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
    // ---------- Chart 1: Historia ----------
    @if ($history->count() > 0)
    new Chart(document.getElementById('chartHistory'), {
        type: 'line',
        data: {
            labels: @json($historyLabels),
            datasets: [{
                label: 'Harga Terakhir',
                data: @json($historyValues),
                borderColor: '#c9a227',
                backgroundColor: 'rgba(201,162,39,.15)',
                pointRadius: 0,
                borderWidth: 1.6,
                fill: true,
                tension: .15,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { maxTicksLimit: 8, maxRotation: 0 } },
                y: { ticks: { callback: v => fmtId(v) } }
            }
        }
    });
    @endif

    // ---------- Chart 2: Evaluasi Testing ----------
    @if ($testPredictions->count() > 0)
    new Chart(document.getElementById('chartTest'), {
        type: 'line',
        data: {
            labels: @json($testLabels),
            datasets: [
                { label: 'Q10', data: @json($testQ10), borderColor: 'transparent', pointRadius: 0, fill: false, order: 4 },
                { label: 'Q90', data: @json($testQ90), borderColor: 'transparent',
                  backgroundColor: 'rgba(201,162,39,.22)', pointRadius: 0, fill: '-1', order: 3 },
                { label: 'Q50 (Prediksi)', data: @json($testQ50), borderColor: '#c9a227', borderDash: [5,4],
                  backgroundColor: 'transparent', pointRadius: 1, order: 2 },
                { label: 'Aktual', data: @json($testActual), borderColor: '#2b2f36', backgroundColor: 'transparent',
                  pointRadius: 1, order: 1 },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 14 } } },
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { maxTicksLimit: 10, maxRotation: 0 } },
                y: { ticks: { callback: v => fmtId(v) } }
            }
        }
    });
    @endif

    // ---------- Chart 3: Forecast ----------
    @if ($forecastPredictions->count() > 0)
    new Chart(document.getElementById('chartForecast'), {
        type: 'line',
        data: {
            labels: @json($fcLabels),
            datasets: [
                { label: 'Rentang Bawah (Q10)', data: @json($fcQ10), borderColor: 'transparent', pointRadius: 0, fill: false, order: 4 },
                { label: 'Rentang Atas (Q90)', data: @json($fcQ90), borderColor: 'transparent',
                  backgroundColor: 'rgba(201,162,39,.25)', pointRadius: 0, fill: '-1', order: 3 },
                { label: 'Prediksi (Q50)', data: @json($fcQ50), borderColor: '#c9a227', borderDash: [6,4],
                  backgroundColor: 'transparent', pointRadius: 2, order: 2 },
                { label: 'Harga Aktual', data: @json($fcActual), borderColor: '#2b2f36', spanGaps: true,
                  backgroundColor: 'transparent', pointRadius: 1.5, order: 1 },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 14 } } },
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { maxTicksLimit: 10, maxRotation: 0 } },
                y: { ticks: { callback: v => fmtId(v) } }
            }
        }
    });
    @endif
</script>
@endpush