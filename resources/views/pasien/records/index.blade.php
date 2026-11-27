@extends('layouts.dashboard')

@section('title', 'Riwayat & Verifikasi Pasien')
@section('page', 'pasien-records')
@section('topbar_title', 'Panel Pasien')
@section('user_display', $patient->full_name)

@section('sidebar')
    @include('pasien.partials.sidebar')
@endsection

@section('content')
    <div class="page-header">
        <h1 class="page-title">Riwayat &amp; Verifikasi Integritas Rekam Medis</h1>
    </div>

    <div class="panel-card">
        <div id="verif-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="verif-valid" class="alert alert-success d-none" role="alert">
            <div class="d-flex align-items-start">
                <i class="bi bi-patch-check-fill fs-4 me-3"></i>
                <div class="w-100">
                    <strong>Rekam Medis Terverifikasi (VALID) — <span id="verifValidCode">-</span></strong><br>
                    <span class="small d-block mb-2">
                        Hash SHA-256 data database saat ini cocok dengan bukti hash yang tercatat di Smart Contract Sepolia.
                        Data asli dan tidak dimanipulasi.
                    </span>
                    <div class="bg-white text-dark p-2 rounded border small">
                        <div><strong>Hash Database Saat Ini (Recomputed):</strong> <span id="verifValidRecomputed"
                                class="hash-code">-</span></div>
                        <div class="mt-1"><strong>Hash Tersimpan (current_hash):</strong> <span id="verifValidStored"
                                class="hash-code">-</span></div>
                        <div class="mt-1"><strong>Hash On-Chain (Sepolia):</strong> <span id="verifValidOnchain"
                                class="hash-code">-</span></div>
                        <div class="mt-1"><strong>Dokter Terakhir:</strong> <span id="verifValidDoctor">-</span>
                            (<code id="verifValidOnchainDoctor">-</code>)</div>
                        <div class="mt-1"><strong>Timestamp On-Chain:</strong> <span id="verifValidTimestamp">-</span></div>
                        <div class="mt-1" id="verifValidTxWrap">
                            <strong>Transaksi Terakhir:</strong>
                            <a id="verifValidTxLink" href="#" target="_blank" rel="noopener noreferrer" class="ms-1">Lihat
                                di Sepolia Etherscan</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="verif-invalid" class="alert alert-danger d-none" role="alert">
            <div class="d-flex align-items-start">
                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                <div class="w-100">
                    <strong><span id="verifInvalidHeading">PERINGATAN! Integritas Data Tidak Cocok (Dimodifikasi)</span> — <span
                            id="verifInvalidCode">-</span></strong><br>
                    <span id="verifInvalidDesc" class="small d-block mb-2">Isi rekam medis di database tidak sesuai dengan catatan asli di
                        Blockchain Sepolia!</span>
                    <div class="bg-white text-dark p-2 rounded border small">
                        <div><strong>Hash On-Chain (Sepolia):</strong> <span id="verifInvalidOnchain"
                                class="hash-code">-</span></div>
                        <div class="mt-1"><strong>Hash Database Saat Ini (Recomputed):</strong> <span
                                id="verifInvalidRecomputed" class="hash-code text-danger border-danger">-</span></div>
                        <div class="mt-1"><strong>Hash Tersimpan (current_hash):</strong> <span id="verifInvalidStored"
                                class="hash-code">-</span></div>
                        <div class="mt-1"><strong>Dokter Terakhir:</strong> <span id="verifInvalidDoctor">-</span>
                            (<code id="verifInvalidOnchainDoctor">-</code>)</div>
                        <div class="mt-1"><strong>Timestamp On-Chain:</strong> <span id="verifInvalidTimestamp">-</span>
                        </div>
                        <div class="mt-1" id="verifInvalidTxWrap">
                            <strong>Transaksi Terakhir:</strong>
                            <a id="verifInvalidTxLink" href="#" target="_blank" rel="noopener noreferrer" class="ms-1">Lihat
                                di Sepolia Etherscan</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($records->isEmpty())
            <div class="text-center py-4 text-muted">
                <i class="bi bi-file-earmark-x fs-3 d-block mb-2"></i>
                Belum ada riwayat rekam medis untuk akun Anda.
            </div>
        @else
            @php
                $recordsPayload = $records->mapWithKeys(function ($record): array {
                    return [
                        $record->id => [
                            'record_code' => $record->record_code,
                            'record_date' => $record->record_date?->toDateString(),
                            'doctor_name' => $record->doctor->full_name . ' (' . $record->doctor->doctor_code . ')',
                            'complaint' => $record->complaint,
                            'diagnosis' => $record->diagnosis,
                            'treatment' => $record->treatment,
                            'notes' => $record->notes ?? '-',
                            'current_hash' => $record->current_hash,
                            'logs' => $record->logBlockchains->sortByDesc('id')->values()->map(fn($log): array => [
                                'action_type' => $log->action_type,
                                'doctor_name' => $log->doctor ? $log->doctor->full_name . ' (' . $log->doctor->doctor_code . ')' : '-',
                                'onchain_hash' => $log->onchain_hash,
                                'tx_hash' => $log->tx_hash,
                                'block_number' => $log->block_number ?? '-',
                                'created_at' => $log->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') . ' WIB',
                            ])->all(),
                        ],
                    ];
                });
            @endphp

            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>ID Rekam</th>
                            <th>Tanggal</th>
                            <th>Dokter Terakhir</th>
                            <th>Diagnosis</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $index => $record)
                            <tr>
                                <td>{{ $index + 1 }}.</td>
                                <td><strong>{{ $record->record_code }}</strong></td>
                                <td class="text-nowrap">{{ $record->record_date?->toDateString() }}</td>
                                <td>{{ $record->doctor->full_name }} ({{ $record->doctor->doctor_code }})</td>
                                <td>{{ $record->diagnosis }}</td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-info btn-sm text-white me-1 btn-pasien-detail"
                                        data-record-id="{{ $record->id }}">
                                        <i class="bi bi-eye me-1"></i> Detail
                                    </button>
                                    <button type="button" class="btn btn-success btn-sm btn-verify-record"
                                        data-verify-url="{{ route('pasien.records.verify', $record) }}"
                                        data-record-code="{{ $record->record_code }}">
                                        <i class="bi bi-shield-check me-1"></i> Verifikasi
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <script id="pasienRecordsJson"
                type="application/json">{!! json_encode($recordsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="modalDetailRekamMedis" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold" id="pasienDetailModalTitle">Detail Rekam Medis</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Tutup"></button>
                </div>
                <div class="modal-body small">
                    <p class="mb-1"><strong>Tanggal Pemeriksaan:</strong> <span id="pasienDetailDate">-</span></p>
                    <p class="mb-2"><strong>Dokter Terakhir:</strong> <span id="pasienDetailDoctor">-</span></p>
                    <hr>
                    <p class="mb-2"><strong>Keluhan (Complaint):</strong><br><span id="pasienDetailComplaint">-</span></p>
                    <p class="mb-2"><strong>Diagnosis:</strong><br><span id="pasienDetailDiagnosis">-</span></p>
                    <p class="mb-2"><strong>Tindakan (Treatment):</strong><br><span id="pasienDetailTreatment">-</span></p>
                    <p class="mb-3"><strong>Catatan Dokter (Notes):</strong><br><span id="pasienDetailNotes">-</span></p>
                    <div class="mb-3">
                        <strong>Canonical SHA-256 Hash (current_hash):</strong><br>
                        <span id="pasienDetailHash" class="hash-code">-</span>
                    </div>
                    <div>
                        <strong>Riwayat Transaksi Blockchain:</strong>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Aksi</th>
                                        <th>Dokter</th>
                                        <th>Blok</th>
                                        <th>Waktu</th>
                                        <th>Sepolia Etherscan</th>
                                    </tr>
                                </thead>
                                <tbody id="pasienDetailLogsBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endpush
