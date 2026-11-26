@extends('layouts.auth')

@section('title', 'Registrasi Pasien')
@section('page', 'register')
@section('panel_body_class', 'py-3')

@section('content')
    <div class="text-center mb-3">
        <i class="bi bi-person-plus-fill text-info fs-2"></i>
        <h4 class="fw-bold mt-2 mb-1">PENDAFTARAN PASIEN BARU</h4>
        <p class="text-muted small mb-0">Akun publik terdaftar khusus sebagai <strong>Pasien</strong>. Akun Dokter didaftarkan oleh Admin.</p>
    </div>

    <div id="registerWalletAlert" class="alert d-none py-2 small" role="alert"></div>

    @if ($errors->any())
        <div class="alert alert-danger py-2 small" role="alert">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register.store') }}">
        @csrf

        <div class="mb-2">
            <label for="full_name" class="form-label">Nama Lengkap</label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                value="{{ old('full_name') }}"
                class="form-control form-control-dark"
                placeholder="Nama lengkap sesuai identitas"
                required
            >
        </div>

        <div class="row g-2 mb-2">
            <div class="col-sm-6">
                <label for="username" class="form-label">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="{{ old('username') }}"
                    class="form-control form-control-dark"
                    placeholder="Username unik"
                    autocomplete="username"
                    required
                >
            </div>
            <div class="col-sm-6">
                <label for="date_of_birth" class="form-label">Tanggal Lahir</label>
                <input
                    type="date"
                    id="date_of_birth"
                    name="date_of_birth"
                    value="{{ old('date_of_birth') }}"
                    class="form-control form-control-dark"
                    max="{{ now()->toDateString() }}"
                    required
                >
            </div>
        </div>

        <div class="mb-2">
            <label for="gender" class="form-label">Jenis Kelamin</label>
            <select id="gender" name="gender" class="form-select form-control-dark" required>
                <option value="">-- Pilih Jenis Kelamin --</option>
                <option value="Laki-laki" @selected(old('gender') === 'Laki-laki')>Laki-laki</option>
                <option value="Perempuan" @selected(old('gender') === 'Perempuan')>Perempuan</option>
            </select>
        </div>

        <div class="mb-2">
            <label for="address" class="form-label">Alamat Lengkap</label>
            <textarea
                id="address"
                name="address"
                rows="2"
                class="form-control form-control-dark"
                placeholder="Masukkan alamat tempat tinggal"
                required
            >{{ old('address') }}</textarea>
        </div>

        <div class="mb-2">
            <label for="wallet_address" class="form-label">Alamat Wallet Ethereum Sepolia (MetaMask)</label>
            <div class="input-group">
                <input
                    type="text"
                    id="wallet_address"
                    name="wallet_address"
                    value="{{ old('wallet_address') }}"
                    class="form-control form-control-dark font-monospace"
                    placeholder="0x..."
                    required
                >
                <button type="button" id="btnFillWalletFromMetaMask" class="btn btn-outline-info btn-sm fw-semibold">
                    <i class="bi bi-wallet2 me-1"></i> Ambil dari MetaMask
                </button>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-sm-6">
                <label for="password" class="form-label">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control form-control-dark"
                    placeholder="Minimal 8 karakter"
                    autocomplete="new-password"
                    required
                >
            </div>
            <div class="col-sm-6">
                <label for="password_confirmation" class="form-label">Konfirmasi Password</label>
                <input
                    type="password"
                    id="password_confirmation"
                    name="password_confirmation"
                    class="form-control form-control-dark"
                    placeholder="Ulangi password"
                    autocomplete="new-password"
                    required
                >
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-auth w-100 text-white mb-2">
            Selesaikan Pendaftaran Pasien
        </button>
    </form>

    <div class="text-center mt-2">
        <span class="text-muted small">
            Sudah punya akun?
            <a href="{{ route('login') }}" class="auth-link">Masuk di sini</a>
        </span>
    </div>
@endsection
