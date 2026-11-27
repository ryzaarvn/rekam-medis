@extends('layouts.dashboard')

@section('title', 'Dashboard Pasien')
@section('page', 'pasien-dashboard')
@section('topbar_title', 'Panel Pasien')
@section('user_display', $patient->full_name)

@section('sidebar')
    @include('pasien.partials.sidebar')
@endsection

@section('content')
    @php
        if ($patient->is_registered_onchain) {
            $onchainStatusLabel = 'Terdaftar';
            $onchainStatusClass = 'text-success';
            $onchainIcon = 'bi-patch-check';
        } elseif ($patient->registration_tx_hash !== null) {
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
        <h1 class="page-title">Dashboard Pasien</h1>
    </div>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Status On-Chain</div>
                    <div class="stat-card-value fs-5 {{ $onchainStatusClass }}">{{ $onchainStatusLabel }}</div>
                    @if ($patient->registration_tx_hash !== null)
                        <div class="mt-1">
                            <a href="https://sepolia.etherscan.io/tx/{{ $patient->registration_tx_hash }}" target="_blank"
                                rel="noopener noreferrer" class="small text-decoration-none"
                                title="{{ $patient->registration_tx_hash }}">
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
                    <div class="stat-card-label">Total Rekam Medis</div>
                    <div class="stat-card-value">{{ $totalRecordsCount }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-file-earmark-medical"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Dokter Berizin Aktif</div>
                    <div class="stat-card-value">{{ $authorizedDoctorsCount }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Pemeriksaan Terakhir</div>
                    <div class="stat-card-value fs-5">{{ $latestRecord?->record_date?->toDateString() ?? '-' }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-calendar-check"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="panel-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h2 class="h6 fw-bold mb-0">Dokter dengan Akses Aktif</h2>
            </div>
        </div>

        @if ($activeAuthorizations->isEmpty())
            <div class="text-center py-4 text-muted small">
                @if (!$patient->is_registered_onchain)
                    Daftar dokter dengan akses aktif tidak tersedia selama akun Anda belum terdaftar aktif on-chain.
                @else
                    Belum ada dokter yang memiliki izin akses aktif saat ini.
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Kode Dokter</th>
                            <th>Nama Dokter</th>
                            <th>Spesialisasi</th>
                            <th>Alamat Wallet</th>
                            <th>Tx Otorisasi</th>
                            <th>Waktu Otorisasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activeAuthorizations as $authorization)
                            <tr>
                                <td><strong>{{ $authorization->doctor->doctor_code }}</strong></td>
                                <td class="fw-semibold">{{ $authorization->doctor->full_name }}</td>
                                <td>{{ $authorization->doctor->specialization }}</td>
                                <td>
                                    @if ($authorization->doctor->user?->wallet_address)
                                        <span class="hash-code" title="{{ $authorization->doctor->user->wallet_address }}">
                                            {{ substr($authorization->doctor->user->wallet_address, 0, 8) }}...{{ substr($authorization->doctor->user->wallet_address, -6) }}
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
                <h2 class="h6 fw-bold mb-0">Rekam Medis Terbaru</h2>
            </div>
        </div>

        @if ($recentRecords->isEmpty())
            <div class="text-center py-4 text-muted small">
                Belum ada riwayat rekam medis untuk akun Anda.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Kode RM</th>
                            <th>Tanggal</th>
                            <th>Dokter Terakhir</th>
                            <th>Diagnosis</th>
                            <th>Hash Terakhir</th>
                            <th>Tx Terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentRecords as $record)
                            <tr>
                                <td><strong>{{ $record->record_code }}</strong></td>
                                <td class="text-nowrap">{{ $record->record_date?->toDateString() ?? '-' }}</td>
                                <td>
                                    @if ($record->doctor)
                                        <div class="fw-semibold">{{ $record->doctor->full_name }}</div>
                                        <div class="small text-muted">{{ $record->doctor->doctor_code }}</div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>{{ \Illuminate\Support\Str::limit($record->diagnosis, 60) }}</td>
                                <td>
                                    <span class="hash-code" title="{{ $record->current_hash }}">
                                        {{ substr($record->current_hash, 0, 10) }}...{{ substr($record->current_hash, -8) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($record->latestLogBlockchain?->tx_hash)
                                        <a href="https://sepolia.etherscan.io/tx/{{ $record->latestLogBlockchain->tx_hash }}"
                                            target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm"
                                            title="{{ $record->latestLogBlockchain->tx_hash }}">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>
                                            {{ substr($record->latestLogBlockchain->tx_hash, 0, 8) }}...{{ substr($record->latestLogBlockchain->tx_hash, -6) }}
                                        </a>
                                    @else
                                        <span class="text-muted small">-</span>
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
