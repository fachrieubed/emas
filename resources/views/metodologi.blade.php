@extends('layouts.app')

@section('title', 'Metodologi — Prediksi Harga Emas')

@section('content')
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
                <i class="bi bi-journal-text me-1 text-gold"></i>Metodologi
            </li>
        </ol>
    </nav>
    <a href="{{ route('prediksi') }}" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-graph-up-arrow me-1"></i>Lihat Prediksi
    </a>
</div>

<div class="card mb-4">
    <div class="card-header bg-transparent">
        <h5 class="section-title mb-0"><i class="bi bi-journal-text text-gold me-2"></i>Metodologi tsQRF (Time Series Quantile Random Forest)</h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">
            Sistem memprediksi harga emas sebagai <b class="text-dark">rentang bawah–atas</b> (bukan satu angka) menggunakan
            <b class="text-dark">Random Forest dengan estimasi kuantil</b> pada deret waktu. Hasil akhir berupa
            <b class="text-dark">Q10 – Q90</b> (rentang 80%) dan nilai tengah <b class="text-dark">Q50</b>.
        </p>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="section-title mb-2"><i class="bi bi-1-circle text-gold me-2"></i>Fitur (Input)</h6>
                        <ul class="mb-0 text-muted">
                            <li>Harga penutupan (<i>lag</i>) 1–7 hari sebelumnya</li>
                            <li>Rata-rata bergerak sederhana (SMA 7 hari)</li>
                            <li>Persentase perubahan harga harian</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="section-title mb-2"><i class="bi bi-2-circle text-gold me-2"></i>Pembagian Data</h6>
                        <ul class="mb-0 text-muted">
                            <li>Split <b class="text-dark">80% training : 20% testing</b> secara berurutan (tidak acak)</li>
                            <li>Bagian <i>testing</i> digunakan untuk evaluasi keakuratan prediksi</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="section-title mb-2"><i class="bi bi-3-circle text-gold me-2"></i>Model Random Forest</h6>
                        <ul class="mb-0 text-muted">
                            <li><b class="text-dark">500 pohon keputusan</b> (n_estimators)</li>
                            <li>Minimum leaf <b class="text-dark">5</b> · fitur per split <b class="text-dark">sqrt</b></li>
                            <li>Seed tetap <b class="text-dark">42</b> agar hasil dapat direproduksi</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="section-title mb-2"><i class="bi bi-4-circle text-gold me-2"></i>Kuantil dari Residual</h6>
                        <ul class="mb-0 text-muted">
                            <li>Residual tiap pohon dihitung dari data training</li>
                            <li>Residual dijadikan <b class="text-dark">rentang ketidakpastian</b></li>
                            <li>Dihasilkan <b class="text-dark">Q10</b> (bawah), <b class="text-dark">Q50</b> (tengah), <b class="text-dark">Q90</b> (atas)</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="section-title mb-2"><i class="bi bi-5-circle text-gold me-2"></i>Evaluasi Model</h6>
                        <ul class="mb-0 text-muted">
                            <li><b class="text-dark">MAE</b> — rata-rata kesalahan absolut</li>
                            <li><b class="text-dark">RMSE</b> — akar kuadrat kesalahan rata-rata</li>
                            <li><b class="text-dark">MAPE</b> — persentase kesalahan rata-rata</li>
                            <li><b class="text-dark">Coverage</b> — % data aktual yang masuk rentang Q10–Q90</li>
                            <li><b class="text-dark">Lebar interval</b> — rata-rata lebar Q10–Q90</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="section-title mb-2"><i class="bi bi-6-circle text-gold me-2"></i>Peramalan ke Depan</h6>
                        <ul class="mb-0 text-muted">
                            <li>Diramalkan <b class="text-dark">7 hari ke depan</b> secara <i>recursive</i></li>
                            <li>Hasil hari sebelumnya dipakai sebagai input hari berikutnya</li>
                            <li>Setiap hari menghasilkan rentang Q10–Q90 + nilai tengah Q50</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-light border d-flex gap-3 mt-4 mb-0">
            <i class="bi bi-info-circle fs-5 text-gold"></i>
            <div class="small text-muted">
                Model ditulis <b class="text-dark">murni dalam PHP</b> tanpa dependensi Python/sklearn.
                Setelah data diunggah atau diubah, model dilatih ulang otomatis di latar belakang.
            </div>
        </div>
    </div>
</div>
@endsection