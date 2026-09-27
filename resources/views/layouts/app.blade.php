<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Prediksi Harga Emas') — tsQRF</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/icons/bootstrap-icons.min.css') }}">
    <style>
        :root {
        --gold: #c9a227;
        --gold-dark: #a57e0e;
        --ink: #26241e;
        }
        body { background: #f6f4ee; color: #26241e; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; font-size: .95rem; }
        a { color: var(--gold-dark); }
        a:hover { color: var(--gold); }
        code { background: #f1ecdd; padding: 2px 7px; border-radius: 6px; font-size: .85em; color: #7a5d10; }

        .navbar-gold { background: #fffdf6; border-bottom: 2px solid var(--gold); }
        .navbar-gold .navbar-brand { color: var(--gold-dark); font-weight: 700; letter-spacing: .4px; }
        .navbar-gold .navbar-brand small { color: #94896a; }
        .navbar-gold .nav-link { color: #44402f; }
        .navbar-gold .nav-link:hover, .navbar-gold .nav-link.active { color: var(--gold-dark); font-weight: 600; }

        .text-gold { color: var(--gold); }
        .bg-gold { background-color: var(--gold); }
        .badge-gold { background: #f3e9c2; color: #6b5410; }

        .card { border: 1px solid #e7e1d1; border-radius: 12px; box-shadow: 0 4px 14px rgba(90, 70, 0, .055); }
        .section-title { font-weight: 700; }

        .stat-value { font-size: 1.4rem; font-weight: 700; }
        .stat-value.text-gold { color: var(--gold-dark); }
        .stat-label { font-size: .76rem; text-transform: uppercase; letter-spacing: .6px; color: #8a8578; }

        .range-box {
        background: linear-gradient(150deg, #fdf9ec, #f5edda);
        color: #2c2716;
        border: 1px solid #e4d29c;
        border-radius: 14px;
        }
        .fs-range { font-size: 2.1rem; font-weight: 800; color: var(--gold-dark); }

        .table-data thead th { font-size: .74rem; text-transform: uppercase; letter-spacing: .5px; color: #8a8578; border-bottom-width: 2px; background: #faf8f1; }
        .table-data td { vertical-align: middle; }

        .btn-gold { background: var(--gold); border-color: var(--gold); color: #fff; font-weight: 600; }
        .btn-gold:hover { background: var(--gold-dark); border-color: var(--gold-dark); color: #fff; }
        .btn-outline-warning { color: var(--gold-dark); border-color: rgba(201, 162, 39, .5); }
        .btn-outline-warning:hover { background: rgba(201, 162, 39, .12); color: var(--gold-dark); }

        .progress-gold { background-color: #ece5d2; }
        .progress-gold .progress-bar { background: linear-gradient(90deg, var(--gold), #e6c85c); }
        .spin-gold { color: var(--gold); }
        .toast-container { z-index: 1080; }
        #uploadSpinner { display: none; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-gold">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('dashboard') }}">
            <i class="bi bi-gem fs-4"></i>
            <span>Prediksi Harga Emas <small class="fw-light opacity-75">· tsQRF</small></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Buka menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active fw-semibold' : '' }}" href="{{ route('dashboard') }}">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('prediksi') ? 'active fw-semibold' : '' }}" href="{{ route('prediksi') }}">
                        <i class="bi bi-graph-up-arrow me-1"></i>Prediksi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('metodologi') ? 'active fw-semibold' : '' }}" href="{{ route('metodologi') }}">
                        <i class="bi bi-journal-text me-1"></i>Metodologi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('admin.*') ? 'active fw-semibold' : '' }}" href="{{ auth()->check() ? route('admin.index') : route('login') }}">
                        <i class="bi bi-shield-lock me-1"></i>Admin
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                @auth
                    <span class="text-muted small me-2">
                        <i class="bi bi-person-circle me-1"></i>{{ auth()->user()->name }}
                        @if(auth()->user()->role === 'admin')
                            <span class="badge badge-gold ms-1">Admin</span>
                        @endif
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-box-arrow-right me-1"></i>Logout
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-person-lock me-1"></i>Login Admin
                    </a>
                @endauth
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-4 py-4">
    @if (session('uploadStarted'))
        <div class="alert alert-success alert-dismissible fade show" id="startedBanner" role="alert" data-upload-id="{{ session('uploadId') }}">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Upload diterima.</strong> Proses parsing & pelatihan model berjalan di background.
            @unless(session('spawned'))
                <br><small class="text-muted">Proses background gagal di-spawn. Jalankan manual:<br>
                <code>php artisan gold:process {{ session('uploadId') }}</code></small>
            @endunless
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('retrainStarted'))
        <div class="alert alert-warning alert-dismissible fade show" id="retrainBanner" role="alert">
            <i class="bi bi-arrow-repeat me-2"></i>
            <strong>Pelatihan ulang dimulai.</strong> Model dilatih ulang dari data yang ada di database (background).
            @unless(session('spawned'))
                <br><small class="text-muted">Proses background gagal di-spawn. Jalankan manual:<br>
                <code>php artisan gold:retrain</code></small>
            @endunless
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><strong>Periksa kembali:</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<!-- Banner proses training -->
<div id="processingBanner" class="d-none">
    <div class="alert alert-warning d-flex align-items-start gap-3 shadow-sm">
        <i class="bi bi-gear-wide-connected spin-gold fs-4"></i>
        <div class="flex-grow-1">
            <h6 class="mb-1">Sedang memproses data & melatih model tsQRF…</h6>
            <p class="mb-1" id="procMessage">Membaca file…</p>
            <div class="progress progress-gold" style="height: 12px">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="procBar" style="width: 15%"></div>
            </div>
            <small class="text-muted" id="procDetail"></small>
        </div>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
</div>

<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/js/chart.umd.min.js') }}"></script>
<script>
    Chart.defaults.font.family = '-apple-system, "Segoe UI", sans-serif';
    Chart.defaults.color = '#6c6f76';
    Chart.defaults.borderColor = 'rgba(0, 0, 0, .08)';
    const fmtId = (v, dec = 0) => Number(v).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });

    // Pemantauan status pelatihan
    (function pollTraining() {
        const banner = document.getElementById('processingBanner');
        const started = document.getElementById('startedBanner');
        const retrainFlag = document.getElementById('retrainBanner');
        let uploadId = started ? started.dataset.uploadId : null;
        let pollLatest = !!retrainFlag;
        const uploadDataEl = document.getElementById('uploadData');

        if (uploadDataEl && uploadDataEl.dataset.uploadId && uploadDataEl.dataset.uploadStatus === 'processing') {
            uploadId = uploadDataEl.dataset.uploadId;
        }
        if (started) { started.classList.remove('show'); started.remove(); }
        if (retrainFlag) { setInterval(() => { retrainFlag.classList.remove('show'); retrainFlag.remove(); }, 15000); }

        if (!uploadId && !pollLatest) return;

        banner.classList.remove('d-none');
        let ticks = 0;
        const endpoint = uploadId
            ? `{{ url('/training') }}/${uploadId}/status`
            : `{{ url('/training/latest/status') }}`;
        (function tick() {
            fetch(endpoint)
                .then(r => r.json())
                .then(s => {
                    ticks++;
                    document.getElementById('procMessage').textContent = s.message || s.model_message || '…';
                    const pct = Math.min(90, 12 + Math.min(ticks, 40) * 1.8);
                    document.getElementById('procBar').style.width = pct + '%';
                    document.getElementById('procDetail').textContent = s.model_status === 'processing' ? `Pelatihan... (${s.model_message || ''})` : '';

                    if (s.done) {
                        document.getElementById('procBar').style.width = '100%';
                        setTimeout(() => { window.location.reload(); }, 800);
                    } else {
                        setTimeout(tick, 2200);
                    }
                })
                .catch(() => { setTimeout(tick, 4000); });
        })();
    })();
</script>
@stack('scripts')
</body>
</html>
