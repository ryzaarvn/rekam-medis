@extends('layouts.dashboard')

@section('title', 'Akun Pengguna')
@section('page', 'account-edit')
@section('topbar_title', $topbarTitle)
@section('user_display', $userDisplay)

@section('sidebar')
    @include($sidebar)
@endsection

@section('content')
    <div style="max-width: 920px; margin: 0 auto;">
        <div class="page-header">
            <h1 class="page-title">
                Akun Pengguna
            </h1>
        </div>

        {{-- Bagian 1: Kartu Kredensial Akun (Read-Only) --}}
        <div class="panel-card" id="accountCredentialsCard">
            <h2 class="h6 fw-bold mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-person-vcard" style="color: var(--primary-teal);"></i>
                Kredensial Akun
            </h2>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-muted fw-semibold mb-1">Username</div>
                    <div class="fw-semibold">{{ $user->username }}</div>
                </div>

                @if (filled($user->email))
                    <div class="col-md-6">
                        <div class="small text-muted fw-semibold mb-1">Email</div>
                        <div class="fw-semibold">{{ $user->email }}</div>
                    </div>
                @endif

                <div class="col-md-6">
                    <div class="small text-muted fw-semibold mb-1">Peran (Role)</div>
                    <div>
                        <span class="badge {{ $roleBadgeClass }}">{{ $roleLabel }}</span>
                    </div>
                </div>

                <div class="col-12">
                    <div class="small text-muted fw-semibold mb-1">Alamat Wallet Sepolia</div>
                    <div>
                        <span class="hash-code d-inline-block">{{ $user->wallet_address }}</span>
                    </div>
                    <div class="form-text mt-1">
                        Alamat wallet terikat ke smart contract Sepolia dan hanya dapat dikelola oleh Admin.
                    </div>
                </div>
            </div>

            @if ($user->isDoctor() && $user->doctor !== null)
                <hr class="my-3">
                <h3 class="h6 fw-bold mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-person-badge" style="color: var(--primary-teal);"></i>
                    Ringkasan Profil Dokter
                </h3>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-muted fw-semibold mb-1">Nama Lengkap</div>
                        <div class="fw-semibold">{{ $user->doctor->full_name }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted fw-semibold mb-1">Kode Dokter</div>
                        <div class="fw-semibold">{{ $user->doctor->doctor_code }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted fw-semibold mb-1">Spesialisasi</div>
                        <div>{{ $user->doctor->specialization }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted fw-semibold mb-1">No. STR</div>
                        <div>{{ $user->doctor->str_number }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted fw-semibold mb-1">Status On-Chain</div>
                        <div>
                            @if ($user->doctor->is_registered_onchain)
                                <span class="badge bg-success">Terdaftar</span>
                            @elseif ($user->doctor->registration_tx_hash !== null)
                                <span class="badge bg-warning text-dark">Dinonaktifkan</span>
                            @else
                                <span class="badge bg-secondary">Belum Terdaftar</span>
                            @endif
                        </div>
                    </div>
                </div>
            @elseif ($user->isPatient() && $user->patient !== null)
                <hr class="my-3">
                <h3 class="h6 fw-bold mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-people" style="color: var(--primary-teal);"></i>
                    Ringkasan Profil Pasien
                </h3>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted fw-semibold mb-1">Nama Lengkap</div>
                        <div class="fw-semibold">{{ $user->patient->full_name }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted fw-semibold mb-1">Kode Pasien</div>
                        <div class="fw-semibold">{{ $user->patient->patient_code }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted fw-semibold mb-1">Status On-Chain</div>
                        <div>
                            @if ($user->patient->is_registered_onchain)
                                <span class="badge bg-success">Terdaftar</span>
                            @elseif ($user->patient->registration_tx_hash !== null)
                                <span class="badge bg-warning text-dark">Dinonaktifkan</span>
                            @else
                                <span class="badge bg-secondary">Belum Terdaftar</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Bagian 2: Form Ubah Username & Email --}}
        <div class="panel-card">
            <h2 class="h6 fw-bold mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-pencil-square" style="color: var(--primary-teal);"></i>
                Ubah Username &amp; Email
            </h2>

            <form method="POST" action="{{ route('account.credentials.update') }}">
                @csrf
                @method('PUT')

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="username" class="form-label fw-semibold">
                            Username <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            value="{{ old('username', $user->username) }}"
                            class="form-control @error('username') is-invalid @enderror"
                            autocomplete="username"
                            required
                        >
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            Minimal 3 karakter. Gunakan huruf, angka, titik (.), garis bawah (_), atau tanda hubung (-).
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="email" class="form-label fw-semibold">
                            Email <span class="text-muted fw-normal">(Opsional)</span>
                        </label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="{{ old('email', $user->email) }}"
                            class="form-control @error('email') is-invalid @enderror"
                            placeholder="nama@contoh.com"
                            autocomplete="email"
                        >
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            Anda dapat menambahkan, memperbarui, atau mengosongkan email sesuai keinginan.
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="bi bi-check-lg me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

        {{-- Bagian 3: Form Ubah Password (Hanya 2 Kolom) --}}
        <div class="panel-card">
            <h2 class="h6 fw-bold mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-key" style="color: var(--primary-teal);"></i>
                Ubah Password
            </h2>

            <form method="POST" action="{{ route('account.password.update') }}">
                @csrf
                @method('PUT')

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="password" class="form-label fw-semibold">
                            Password Baru <span class="text-danger">*</span>
                        </label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control @error('password') is-invalid @enderror"
                            autocomplete="new-password"
                            required
                        >
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Minimal 8 karakter.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="password_confirmation" class="form-label fw-semibold">
                            Konfirmasi Password Baru <span class="text-danger">*</span>
                        </label>
                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            class="form-control @error('password_confirmation') is-invalid @enderror"
                            autocomplete="new-password"
                            required
                        >
                        @error('password_confirmation')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="bi bi-shield-lock me-1"></i> Simpan Password
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
