@extends('layouts.app')

@section('title', 'Login Admin — Prediksi Harga Emas')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5 col-xl-4">
        <div class="card shadow-sm mt-4">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <div class="bg-gold text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px">
                        <i class="bi bi-shield-lock fs-3"></i>
                    </div>
                    <h4 class="section-title mb-1">Login Admin</h4>
                    <p class="text-muted small mb-0">Khusus pengguna dengan akses admin</p>
                </div>

                <form method="POST" action="{{ route('login.attempt') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email') }}" placeholder="admin@example.com" required autofocus autocomplete="username">
                        </div>
                        @error('email')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="••••••••"
                                   required autocomplete="current-password">
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remember" id="rememberMe" value="1">
                        <label class="form-check-label small" for="rememberMe">Ingat saya di perangkat ini</label>
                    </div>

                    <button type="submit" class="btn btn-gold w-100 py-2">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Masuk
                    </button>
                </form>

                <hr>
                <p class="text-center text-muted small mb-0">
                    <i class="bi bi-key me-1"></i>Akun admin dibuat via perintah
                    <code>php artisan gold:make-admin</code>
                </p>
                <p class="text-center mt-2 mb-0">
                    <a href="{{ route('dashboard') }}" class="small text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
                    </a>
                </p>
            </div>
        </div>
    </div>
</div>
@endsection