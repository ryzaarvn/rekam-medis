@extends('layouts.auth')

@section('title', 'Masuk')
@section('page', 'login')

@section('content')
    <div class="text-center mb-4">
        <i class="bi bi-shield-lock-fill text-info fs-1"></i>
        <h3 class="fw-bold mt-2 mb-1">E-REKAM MEDIS</h3>
        <p class="text-muted small">Akses Sistem melalui Akun Anda</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success py-2 small" role="alert">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger py-2 small" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                value="{{ old('username') }}"
                class="form-control form-control-dark @error('username') is-invalid @enderror"
                placeholder="Masukkan username"
                autocomplete="username"
                required
                autofocus
            >
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-control form-control-dark @error('password') is-invalid @enderror"
                placeholder="Masukkan password"
                autocomplete="current-password"
                required
            >
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))>
            <label class="form-check-label small text-muted" for="remember">
                Ingat sesi saya
            </label>
        </div>

        <button type="submit" class="btn btn-primary btn-auth w-100 text-white mb-3">
            Masuk ke Dashboard
        </button>
    </form>

    <div class="text-center mt-3">
        <span class="text-muted small">
            Pasien baru?
            <a href="{{ route('register') }}" class="auth-link">Daftar akun pasien di sini</a>
        </span>
    </div>
@endsection
