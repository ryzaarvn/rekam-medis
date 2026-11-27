@extends('layouts.dashboard')

@section('title', 'Dashboard Dokter')
@section('page', 'dokter-dashboard')
@section('topbar_title', 'Panel Dokter')
@section('user_display', $doctor->full_name)

@section('sidebar')
    @include('dokter.partials.sidebar')
@endsection

@section('content')
    @php
        if ($doctor->is_registered_onchain) {
            $onchainStatusLabel = 'Terdaftar';
            $onchainStatusClass = 'text-success';
            $onchainIcon = 'bi-patch-check';
        } elseif ($doctor->registration_tx_hash !== null) {
            $onchainStatusLabel = 'Dinonaktifkan';
            $onchainStatusClass = 'text-warning';
            $onchainIcon = 'bi-slash-circle';
        } else {
            $onchainStatusLabel = 'Belum Terdaftar';
            $onchainStatusClass = 'text-secondary';
            $onchainIcon = 'bi-hourglass-split';
        }
    @endphp

    <div class="page-header">
        <h1 class="page-title">Dashboard Dokter</h1>
    </div>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Status On-Chain</div>
                    <div class="stat-card-value fs-5 {{ $onchainStatusClass }}">{{ $onchainStatusLabel }}</div>
                    @if ($doctor->registration_tx_hash !== null)
                        <div class="mt-1">
                            <a href="https://sepolia.etherscan.io/tx/{{ $doctor->registration_tx_hash }}" target="_blank"
                                rel="noopener noreferrer" class="small text-decoration-none"
                                title="{{ $doctor->registration_tx_hash }}">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Tx Registrasi
                            </a>
                        </div>
                    @endif
                </div>
                <div class="stat-card-icon {{ $onchainStatusClass }}">
                    <i class="bi {{ $onchainIcon }}"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Pasien Terotorisasi</div>
                    <div class="stat-card-value">{{ $authorizedPatientsCount }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-people"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Rekam Medis Diakses</div>
                    <div class="stat-card-value">{{ $accessibleRecordsCount }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-file-earmark-medical"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Transaksi Blockchain</div>
                    <div class="stat-card-value">{{ $myLogsTotalCount }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-activity"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="panel-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h2 class="h6 fw-bold mb-0">Pasien Terotorisasi Aktif</h2>
            </div>
        </div>

        @if ($recentAuthorizedPatients->isEmpty())
            <div class="text-center py-4 text-muted small">
                @if (!$doctor->is_registered_onchain)
                    Data pasien terotorisasi tidak tersedia selama akun dokter belum aktif on-chain.
                @else
                    Belum ada pasien terdaftar on-chain yang memberikan izin akses aktif kepada Anda.
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Kode Pasien</th>
                            <th>Nama Pasien</th>
                            <th>Jenis Kelamin</th>
                            <th>Alamat Wallet</th>
                            <th>Tx Otorisasi</th>
                            <th>Waktu Otorisasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentAuthorizedPatients as $authorization)
                            <tr>
                                <td><strong>{{ $authorization->patient->patient_code }}</strong></td>
                                <td class="fw-semibold">{{ $authorization->patient->full_name }}</td>
                                <td>{{ $authorization->patient->gender }}</td>
                                <td>
                                    @if ($authorization->patient->user?->wallet_address)
                                        <span class="hash-code" title="{{ $authorization->patient->user->wallet_address }}">
                                            {{ substr($authorization->patient->user->wallet_address, 0, 8) }}...{{ substr($authorization->patient->user->wallet_address, -6) }}
                                        </span>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($authorization->tx_hash)
                                        <a href="https://sepolia.etherscan.io/tx/{{ $authorization->tx_hash }}" target="_blank"
                                            rel="noopener noreferrer" class="btn btn-outline-primary btn-sm"
                                            title="{{ $authorization->tx_hash }}">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>
                                            {{ substr($authorization->tx_hash, 0, 8) }}...{{ substr($authorization->tx_hash, -6) }}
                                        </a>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                                <td class="small text-nowrap">
                                    {{ $authorization->updated_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') }} WIB
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="panel-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h2 class="h6 fw-bold mb-0">Aktivitas Blockchain Terbaru</h2>
            </div>
        </div>

        @if ($recentBlockchainLogs->isEmpty())
            <div class="text-center py-4 text-muted small">
                @if (!$doctor->is_registered_onchain)
                    Aktivitas transaksi blockchain tidak tersedia selama akun dokter belum aktif on-chain.
                @else
                    Belum ada log transaksi blockchain dari pasien terotorisasi.
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Kode RM</th>
                            <th>Pasien</th>
                            <th>Aksi</th>
                            <th>Hash On-Chain</th>
                            <th>Tx Hash</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentBlockchainLogs as $log)
                            <tr>
                                <td class="small text-nowrap">
                                    {{ $log->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') }} WIB
                                </td>
                                <td><strong>{{ $log->medicalRecord?->record_code ?? '-' }}</strong></td>
                                <td>
                                    @if ($log->medicalRecord?->patient)
                                        <div class="fw-semibold">{{ $log->medicalRecord->patient->full_name }}</div>
                                        <div class="small text-muted">{{ $log->medicalRecord->patient->patient_code }}</div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($log->action_type === \App\Models\LogBlockchain::ACTION_CREATE)
                                        <span class="badge bg-success">CREATE</span>
                                    @else
                                        <span class="badge bg-warning text-dark">UPDATE</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="hash-code" title="{{ $log->onchain_hash }}">
                                        {{ substr($log->onchain_hash, 0, 10) }}...{{ substr($log->onchain_hash, -8) }}
                                    </span>
                                </td>
                                <td>
                                    <a href="https://sepolia.etherscan.io/tx/{{ $log->tx_hash }}" target="_blank"
                                        rel="noopener noreferrer" class="btn btn-outline-primary btn-sm"
                                        title="{{ $log->tx_hash }}">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>
                                        {{ substr($log->tx_hash, 0, 8) }}...{{ substr($log->tx_hash, -6) }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
