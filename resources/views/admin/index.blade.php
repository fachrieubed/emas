@extends('layouts.app')

@section('title', 'Admin — Manajemen Data Emas')

@section('content')
@if ($processing)
<div id="uploadData" data-upload-id="{{ $processing->id }}" data-upload-status="{{ $processing->status }}"></div>
@endif

{{-- ===================== BREADCRUMB / KEMBALI ===================== --}}
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item">
                <a href="{{ route('dashboard') }}" class="text-decoration-none">
                    <i class="bi bi-speedometer2 me-1"></i>Dashboard
                </a>
            </li>
            <li class="breadcrumb-item active fw-semibold" aria-current="page">
                <i class="bi bi-shield-lock me-1"></i>Admin
            </li>
        </ol>
    </nav>
    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-outline-warning">
        <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
    </a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h4 class="section-title mb-1"><i class="bi bi-shield-lock text-gold me-2"></i>Manajemen Data Emas</h4>
                <p class="text-muted mb-0 small">
                    Upload file CSV, edit, hapus, atau hapus semua data pergerakan emas.
                    @if ($modelRun)
                        Model terakhir: <b>run #{{ $modelRun->id }}</b> ({{ $modelRun->n_estimators }} pohon,
                        MAE {{ number_format((float) $modelRun->mae, 0, ',', '.') }}).
                    @endif
                    <span class="text-gold">Setelah mengubah data, klik <b>Latih Ulang</b> agar prediksi diperbarui.</span>
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#uploadModal">
                    <i class="bi bi-cloud-upload me-1"></i>Upload Data Emas
                </button>
                <form method="POST" action="{{ route('admin.retrain') }}" onsubmit="return confirm('Latih ulang model tsQRF dengan data saat ini?')">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning">
                        <i class="bi bi-arrow-repeat me-1"></i>Latih Ulang Model
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-light border d-flex flex-wrap align-items-center gap-2 mb-4">
    <i class="bi bi-arrow-repeat text-gold fs-5"></i>
    <span class="small mb-0">
        <b>Pembaruan otomatis aktif:</b> data emas ditarik otomatis setiap hari pukul <b>17:30</b> dari Yahoo Finance
        (baris akhir pekan &amp; kolom kosong otomatis dibuang, tanggal yang bolong ditambal), lalu model dilatih ulang.
        Upload CSV manual tetap bisa dilakukan di bawah.
    </span>
</div>

<div class="card mb-4">
    <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="section-title mb-0"><i class="bi bi-database text-gold me-2"></i>Data Pergerakan Emas ({{ number_format($data->total(), 0, ',', '.') }} baris)</h6>
        <form method="GET" action="{{ route('admin.index') }}" class="d-flex gap-2">
            <input type="search" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="Cari tanggal / harga…" style="width:210px">
            <select name="per_page" class="form-select form-select-sm" style="width:90px" onchange="this.form.submit()">
                <option value="25" @selected(request('per_page', 25) == 25)>25</option>
                <option value="50" @selected(request('per_page') == 50)>50</option>
                <option value="100" @selected(request('per_page') == 100)>100</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-data table-hover mb-0">
            <thead>
                <tr>
                    <th class="text-center fw-bold" style="width:60px">No</th>
                    <th>Tanggal</th>
                    <th class="text-end">Terakhir</th>
                    <th class="text-end">Pembukaan</th>
                    <th class="text-end">Tertinggi</th>
                    <th class="text-end">Terendah</th>
                    <th class="text-end">Vol.</th>
                    <th class="text-end">Perubahan%</th>
                    <th class="text-center" style="width:150px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data as $i => $row)
                <tr>
                    <td class="text-center text-muted">{{ $data->firstItem() + $i }}</td>
                    <td class="fw-semibold" data-label="{{ $row->tanggal->format('Y-m-d') }}">{{ $row->tanggal->format('d/m/Y') }}</td>
                    <td class="text-end">{{ number_format((float) $row->terakhir, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $row->pembukaan, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $row->tertinggi, 0, ',', '.') }}</td>
                    <td class="text-end">{{ number_format((float) $row->terendah, 0, ',', '.') }}</td>
                    <td class="text-end">{{ $row->volume !== null ? number_format((float) $row->volume, 0, ',', '.') : '-' }}</td>
                    <td class="text-end">
                        @if ($row->perubahan !== null)
                            <span class="badge {{ $row->perubahan >= 0 ? 'text-bg-success' : 'text-bg-danger' }}">
                                {{ $row->perubahan >= 0 ? '▲' : '▼' }} {{ number_format(abs((float) $row->perubahan), 2, ',', '.') }}%
                            </span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-secondary me-1 btn-edit"
                                data-id="{{ $row->id }}"
                                data-tanggal="{{ $row->tanggal->format('Y-m-d') }}"
                                data-terakhir="{{ $row->terakhir }}"
                                data-pembukaan="{{ $row->pembukaan }}"
                                data-tertinggi="{{ $row->tertinggi }}"
                                data-terendah="{{ $row->terendah }}"
                                data-volume="{{ $row->volume ?? '' }}"
                                data-perubahan="{{ $row->perubahan ?? '' }}"
                                data-bs-toggle="modal" data-bs-target="#editModal"
                                title="Edit data">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <form method="POST" action="{{ route('admin.destroy', $row->id) }}" class="d-inline"
                              onsubmit="return confirm('Hapus data tanggal {{ $row->tanggal->format('d/m/Y') }}?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" title="Hapus data"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">Tidak ada data emas.
                        @guest <a href="{{ route('login') }}">Login admin</a> lalu upload file CSV.
                        @else Gunakan tombol <b>Upload Data Emas</b> di atas.
                        @endguest
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($data->hasPages())
    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted">Menampilkan {{ $data->firstItem() ?? 0 }}–{{ $data->lastItem() ?? 0 }} dari {{ number_format($data->total(), 0, ',', '.') }} baris</small>
        <div>{!! $data->links() !!}</div>
    </div>
    @endif
</div>

{{-- ===================== DANGER ZONE: HAPUS SEMUA ===================== --}}
<div class="card border-danger mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h6 class="section-title text-danger mb-1"><i class="bi bi-exclamation-octagon me-2"></i>Zona Berbahaya</h6>
                <p class="text-muted mb-0 small">Menghapus <b>semua</b> data emas, hasil model & prediksi sekaligus. Tindakan ini tidak dapat dibatalkan.</p>
            </div>
            <form method="POST" action="{{ route('admin.destroy-all') }}"
                  onsubmit="return confirm('PERINGATAN: Hapus SEMUA data emas, model, dan prediksi? Tindakan ini tidak bisa diurungkan!')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash3 me-1"></i>Hapus Semua Data
                </button>
            </form>
        </div>
    </div>
</div>

@include('partials.upload-modal')

{{-- ===================== MODAL EDIT ===================== --}}
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" id="editForm" class="modal-content">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square text-gold me-2"></i>Edit Data Emas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" required>
                    </div>
                    <div class="col-md-8"></div>
                    <div class="col-md-6">
                        <label class="form-label">Terakhir</label>
                        <input type="text" inputmode="decimal" name="terakhir" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Pembukaan</label>
                        <input type="text" inputmode="decimal" name="pembukaan" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tertinggi</label>
                        <input type="text" inputmode="decimal" name="tertinggi" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Terendah</label>
                        <input type="text" inputmode="decimal" name="terendah" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Volume</label>
                        <input type="text" inputmode="decimal" name="volume" class="form-control" placeholder="cth: 93820" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Perubahan %</label>
                        <input type="text" inputmode="decimal" name="perubahan" class="form-control" placeholder="cth: 0.27" required>
                    </div>
                </div>
                <div class="alert alert-light border small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Gunakan titik sebagai desimal (cth <code>1886158.25</code>). Format Indonesia juga didukung
                    (<code>1.886.158,25</code>).
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg me-1"></i>Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
    // Isi modal edit dari tombol action
    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const f = document.getElementById('editForm');
            f.action = `{{ url('/admin/data') }}/${btn.dataset.id}`;
            f.querySelector('[name=tanggal]').value = btn.dataset.tanggal;
            f.querySelector('[name=terakhir]').value = btn.dataset.terakhir;
            f.querySelector('[name=pembukaan]').value = btn.dataset.pembukaan;
            f.querySelector('[name=tertinggi]').value = btn.dataset.tertinggi;
            f.querySelector('[name=terendah]').value = btn.dataset.terendah;
            f.querySelector('[name=volume]').value = btn.dataset.volume || '';
            f.querySelector('[name=perubahan]').value = btn.dataset.perubahan || '';
        });
    });
</script>
@endpush