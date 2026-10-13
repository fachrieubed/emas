@extends('layouts.app')

@section('title', 'Dashboard — Prediksi Harga Emas')

@section('content')
@php
    $fmt = fn ($v, $dec = 0) => number_format((float) $v, $dec, ',', '.');
@endphp

@if ($processing)
<div id="uploadData" data-upload-id="{{ $processing->id }}" data-upload-status="{{ $processing->status }}"></div>
@endif

{{-- ===================== BREADCRUMB / NAVIGASI ===================== --}}
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item active fw-semibold" aria-current="page">
                <i class="bi bi-speedometer2 me-1 text-gold"></i>Dashboard
            </li>
        </ol>
    </nav>
    <a href="{{ auth()->check() ? route('admin.index') : route('login') }}" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-shield-lock me-1"></i>Kelola Data (Admin)
    </a>
</div>

{{-- ===================== STATUS SINKRONISASI ===================== --}}
<div class="alert alert-light border d-flex flex-wrap align-items-center gap-2 mb-4 py-2">
    <i class="bi bi-arrow-repeat {{ ($lastSync['status'] ?? '') === 'error' ? 'text-danger' : 'text-gold' }}"></i>
    <span class="small mb-0">
        @if ($lastSync && ($lastSync['status'] ?? 'ok') === 'ok')
            <b>Pembaruan otomatis aktif.</b> Terakhir sinkron:
            <b>{{ \Carbon\Carbon::parse($lastSync['at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</b>
            ({{ $lastSync['source'] ?? 'Yahoo Finance (GC=F)' }})
            @if (!empty($lastSync['latest_date']))
                · data terakhir {{ \Carbon\Carbon::parse($lastSync['latest_date'])->format('d/m/Y') }}
            @endif
        @elseif ($lastSync && ($lastSync['status'] ?? '') === 'error')
            <span class="text-danger"><b>Sinkronisasi otomatis terakhir gagal.</b> {{ $lastSync['message'] ?? '' }}</span>
        @else
            <b>Pembaruan otomatis:</b> data ditarik setiap hari pukul <b>05:15 WIB</b> (sumber: Yahoo Finance <code>GC=F</code>).
        @endif
    </span>
</div>

{{-- ===================== RINGKASAN ===================== --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <div class="stat-label">Harga Terakhir</div>
                <div class="stat-value mt-1">Rp {{ $fmt($latest->terakhir ?? 0, 0) }}</div>
                <small class="text-muted">{{ $latest ? $latest->tanggal->format('d/m/Y') : '-' }}</small>
                @if ($latest && $latest->perubahan !== null)
                    <span class="badge {{ $latest->perubahan >= 0 ? 'text-bg-success' : 'text-bg-danger' }} align-self-start mt-2">
                        {{ $latest->perubahan >= 0 ? '▲' : '▼' }} {{ $fmt(abs((float) $latest->perubahan), 2) }}%
                    </span>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <div class="stat-label">Total Data</div>
                <div class="stat-value mt-1">{{ number_format($totalData, 0, ',', '.') }}</div>
                <small class="text-muted">mulai {{ $bucketDate ? \Carbon\Carbon::parse($bucketDate)->format('d/m/Y') : '-' }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <a href="{{ route('prediksi') }}" class="card h-100 text-decoration-none">
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="stat-label"><i class="bi bi-graph-up-arrow me-1 text-gold"></i>Prediksi Harga</div>
                <div class="stat-value mt-1 text-gold">Lihat Prediksi</div>
                <small class="text-muted">grafik, rentang Q10–Q90 &amp; hasil peramalan →</small>
            </div>
        </a>
    </div>
</div>

{{-- ===================== DATA EMAS ===================== --}}
<div class="card mb-4">
    <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center">
        <h6 class="section-title mb-0"><i class="bi bi-database text-gold me-2"></i>Data Emas {{ number_format($totalData, 0, ',', '.') }} baris</h6>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <input type="search" id="searchGold" class="form-control form-control-sm" placeholder="Cari tanggal / harga…" style="width:220px">
            <select id="perPage" class="form-select form-select-sm" style="width:90px">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-data table-hover mb-0">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th class="text-end">Terakhir</th>
                    <th class="text-end">Pembukaan</th>
                    <th class="text-end">Tertinggi</th>
                    <th class="text-end">Terendah</th>
                    <th class="text-end">Vol.</th>
                    <th class="text-end">Perubahan%</th>
                </tr>
            </thead>
            <tbody id="goldTableBody">
                <tr><td colspan="7" class="text-center text-muted py-4">Memuat data…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
        <small class="text-muted" id="goldTableInfo"></small>
        <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary" id="prevPage" disabled>« Sebelumnya</button>
            <button class="btn btn-sm btn-outline-secondary" id="nextPage" disabled>Berikutnya »</button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
    // ---------- Tabel data emas ----------
    (function goldTable() {
        let page = 1, per = 25, search = '';
        const tbody = document.getElementById('goldTableBody');
        const info = document.getElementById('goldTableInfo');
        const prev = document.getElementById('prevPage');
        const next = document.getElementById('nextPage');

        const load = () => {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Memuat…</td></tr>';
            fetch(`{{ url('/api/gold') }}?page=${page}&per_page=${per}&search=${encodeURIComponent(search)}`)
                .then(r => r.json())
                .then(d => {
                    if (!d.data || !d.data.length) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data.</td></tr>';
                        info.textContent = '0 data';
                    } else {
                        tbody.innerHTML = d.data.map(r => `
                            <tr>
                                <td class="fw-semibold">${new Date(r.tanggal).toLocaleDateString('id-ID')}</td>
                                <td class="text-end">${fmtId(r.terakhir)}</td>
                                <td class="text-end">${fmtId(r.pembukaan)}</td>
                                <td class="text-end">${fmtId(r.tertinggi)}</td>
                                <td class="text-end">${fmtId(r.terendah)}</td>
                                <td class="text-end">${r.volume != null ? Number(r.volume).toLocaleString('id-ID') : '-'}</td>
                                <td class="text-end"><span class="badge ${r.perubahan >= 0 ? 'text-bg-success' : 'text-bg-danger'}">${r.perubahan >= 0 ? '▲' : '▼'} ${Math.abs(r.perubahan).toLocaleString('id-ID', {minimumFractionDigits:2, maximumFractionDigits:2})}%</span></td>
                            </tr>`).join('');
                        info.textContent = `Hal ${d.current_page} dari ${d.last_page} · total ${d.total.toLocaleString('id-ID')}`;
                    }
                    prev.disabled = d.current_page <= 1;
                    next.disabled = d.current_page >= d.last_page;
                    page = d.current_page;
                });
        };

        prev.onclick = () => { page--; load(); };
        next.onclick = () => { page++; load(); };
        document.getElementById('searchGold').addEventListener('input', e => {
            search = e.target.value.trim();
            page = 1;
            load();
        });
        document.getElementById('perPage').addEventListener('change', e => {
            per = +e.target.value;
            page = 1;
            load();
        });
        load();
    })();
</script>
@endpush