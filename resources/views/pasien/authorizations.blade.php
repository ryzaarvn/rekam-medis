@extends('layouts.dashboard')

@section('title', 'Pengelolaan Izin Dokter')
@section('page', 'pasien-authorizations')
@section('topbar_title', 'Portal Pasien')
@section('user_display', $patient->full_name.' ('.$patient->patient_code.')')

@section('sidebar')
    @include('pasien.partials.sidebar')
@endsection

@section('content')
    <div class="page-header">
        <h1 class="page-title"><i class="bi bi-shield-lock"></i> Pengelolaan Izin Akses Dokter</h1>
    </div>

    <div class="panel-card">
        @if (! $patient->is_registered_onchain)
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Akun Belum Terdaftar On-Chain:</strong> Wallet Anda belum terdaftar/aktif di blockchain Sepolia. Hubungi Admin untuk registrasi on-chain sebelum mengatur izin dokter.
            </div>
        @endif

        <p class="text-muted small mb-3">
            Berikan atau cabut izin dokter untuk mencatat dan memperbarui rekam medis Anda. Hanya dokter yang sudah terdaftar aktif
            on-chain di Sepolia yang ditampilkan. Setiap perubahan mengeksekusi transaksi <code>grantAccess</code> atau
            <code>revokeAccess</code> melalui MetaMask Anda.
        </p>

        <div id="authActionAlert" class="alert d-none" role="alert"></div>

        @if ($doctors->isEmpty())
            <div class="text-center py-4 text-muted">
                <i class="bi bi-person-x fs-3 d-block mb-2"></i>
                Belum ada dokter yang terdaftar aktif on-chain di Sepolia.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>ID Dokter</th>
                            <th>Nama Dokter</th>
                            <th>Spesialis</th>
                            <th>Alamat Wallet</th>
                            <th>Status Izin</th>
                            <th>Tx Terakhir</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($doctors as $index => $doctor)
                            @php
                                $authorization = $authorizationsByDoctor->get($doctor->id);
                                $isAuthorized = $authorization?->is_authorized ?? false;
                            @endphp
                            <tr>
                                <td>{{ $index + 1 }}.</td>
                                <td><strong>{{ $doctor->doctor_code }}</strong></td>
                                <td>{{ $doctor->full_name }}</td>
                                <td>{{ $doctor->specialization }}</td>
                                <td>
                                    <span class="hash-code" title="{{ $doctor->user->wallet_address }}">
                                        {{ substr($doctor->user->wallet_address, 0, 8) }}...{{ substr($doctor->user->wallet_address, -6) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($isAuthorized)
                                        <span class="badge bg-success">Diizinkan</span>
                                    @else
                                        <span class="badge bg-secondary">Tidak Diizinkan</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($authorization?->tx_hash)
                                        <a
                                            href="https://sepolia.etherscan.io/tx/{{ $authorization->tx_hash }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="btn btn-outline-primary btn-sm"
                                        >
                                            <i class="bi bi-box-arrow-up-right me-1"></i>
                                            {{ substr($authorization->tx_hash, 0, 8) }}...{{ substr($authorization->tx_hash, -6) }}
                                        </a>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($isAuthorized)
                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm btn-auth-action"
                                            data-action="revoke"
                                            data-url="{{ route('pasien.authorizations.revoke', $doctor) }}"
                                            data-doctor-wallet="{{ $doctor->user->wallet_address }}"
                                            data-doctor-name="{{ $doctor->full_name }}"
                                            @disabled(! $patient->is_registered_onchain)
                                        >
                                            <i class="bi bi-shield-x me-1"></i> Cabut Izin
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            class="btn btn-success btn-sm btn-auth-action"
                                            data-action="grant"
                                            data-url="{{ route('pasien.authorizations.grant', $doctor) }}"
                                            data-doctor-wallet="{{ $doctor->user->wallet_address }}"
                                            data-doctor-name="{{ $doctor->full_name }}"
                                            @disabled(! $patient->is_registered_onchain)
                                        >
                                            <i class="bi bi-shield-check me-1"></i> Berikan Izin
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
