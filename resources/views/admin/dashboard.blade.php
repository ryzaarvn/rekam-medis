@extends('layouts.dashboard')

@section('title', 'Dashboard Admin')
@section('page', 'admin-dashboard')
@section('topbar_title', 'Panel Administrator')
@section('user_display', 'Admin')

@section('sidebar')
    @include('admin.partials.sidebar')
@endsection

@section('content')
    <div class="page-header">
        <h1 class="page-title">Dashboard Admin</h1>
    </div>

    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-5 g-3 mb-4">
        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Total Dokter</div>
                    <div class="stat-card-value">{{ $stats['total_doctors'] }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-person-badge"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Dokter Terdaftar On-Chain</div>
                    <div class="stat-card-value text-success">{{ $stats['onchain_doctors'] }}</div>
                </div>
                <div class="stat-card-icon text-success">
                    <i class="bi bi-patch-check"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Total Pasien</div>
                    <div class="stat-card-value">{{ $stats['total_patients'] }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-people"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Pasien Terdaftar On-Chain</div>
                    <div class="stat-card-value text-success">{{ $stats['onchain_patients'] }}</div>
                </div>
                <div class="stat-card-icon text-success">
                    <i class="bi bi-person-check"></i>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div>
                    <div class="stat-card-label">Total Rekam Medis</div>
                    <div class="stat-card-value">{{ $stats['total_records'] }}</div>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-file-earmark-medical"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="panel-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h2 class="h6 fw-bold mb-0">Aktivitas Blockchain Terbaru</h2>
            </div>
        </div>

        @if ($recentLogs->isEmpty())
            <div class="text-center py-4 text-muted small">
                Belum ada log transaksi blockchain yang tercatat.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Kode Rekam</th>
                            <th>Aksi</th>
                            <th>Dokter</th>
                            <th>On-Chain Hash</th>
                            <th>Blok</th>
                            <th>Transaksi Sepolia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentLogs as $log)
                            <tr>
                                <td class="small text-nowrap">
                                    {{ $log->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') }} WIB
                                </td>
                                <td>
                                    <strong>{{ $log->medicalRecord?->record_code ?? '-' }}</strong>
                                    @if ($log->medicalRecord?->patient)
                                        <div class="small text-muted">{{ $log->medicalRecord->patient->full_name }}</div>
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
                                    <div class="fw-semibold">{{ $log->doctor?->full_name ?? '-' }}</div>
                                    @if ($log->doctor?->user)
                                        <span class="hash-code" title="{{ $log->doctor->user->wallet_address }}">
                                            {{ substr($log->doctor->user->wallet_address, 0, 8) }}...{{ substr($log->doctor->user->wallet_address, -6) }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="hash-code" title="{{ $log->onchain_hash }}">
                                        {{ substr($log->onchain_hash, 0, 10) }}...{{ substr($log->onchain_hash, -8) }}
                                    </span>
                                </td>
                                <td>{{ $log->block_number ?? '-' }}</td>
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