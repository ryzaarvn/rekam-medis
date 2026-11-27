@extends('layouts.dashboard')

@section('title', 'Riwayat Pemeriksaan')
@section('page', 'dokter-records-index')
@section('topbar_title', 'Panel Dokter')
@section('user_display', $doctor->full_name)

@section('sidebar')
    @include('dokter.partials.sidebar')
@endsection

@section('content')
    <div class="page-header">
        <h1 class="page-title">Riwayat Pemeriksaan</h1>
        <a href="{{ route('dokter.records.create') }}" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-plus-lg me-1"></i> Catat Pemeriksaan Baru
        </a>
    </div>

    <div class="panel-card">
        @if (!$doctor->is_registered_onchain)
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Akun Belum Terdaftar On-Chain:</strong> Akun dokter Anda belum terdaftar aktif on-chain oleh Admin,
                sehingga akses daftar dan pembaruan rekam medis pasien dinonaktifkan.
            </div>
        @endif

        @if ($records->isEmpty())
            <div class="text-center py-4 text-muted">
                <i class="bi bi-journal-x fs-3 d-block mb-2"></i>
                @if (!$doctor->is_registered_onchain)
                    Daftar rekam medis tidak tersedia selama akun dokter belum aktif on-chain.
                @else
                    Belum ada rekam medis dari pasien yang memberikan izin aktif kepada Anda saat ini.
                @endif
            </div>
        @else
            @php
                $recordsPayload = $records->mapWithKeys(function ($record): array {
                    return [
                        $record->id => [
                            'record_code' => $record->record_code,
                            'record_date' => $record->record_date?->toDateString(),
                            'patient_name' => $record->patient->full_name . ' (' . $record->patient->patient_code . ')',
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
                            <th>Pasien</th>
                            <th>Dokter Terakhir</th>
                            <th>Diagnosis</th>
                            <th>Tx Terakhir</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $index => $record)
                            @php
                                $isPatientOnchain = $record->patient->is_registered_onchain;
                                $canEdit = $doctor->is_registered_onchain
                                    && $isPatientOnchain
                                    && in_array($record->patient_id, $authorizedPatientIds, true);
                            @endphp
                            <tr>
                                <td>{{ $index + 1 }}.</td>
                                <td><strong>{{ $record->record_code }}</strong></td>
                                <td class="text-nowrap">{{ $record->record_date?->toDateString() }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $record->patient->full_name }}</div>
                                    <small class="text-muted">{{ $record->patient->patient_code }}</small>
                                </td>
                                <td>{{ $record->doctor->full_name }} ({{ $record->doctor->doctor_code }})</td>
                                <td>{{ $record->diagnosis }}</td>
                                <td>
                                    @if ($record->latestLogBlockchain)
                                        <a href="https://sepolia.etherscan.io/tx/{{ $record->latestLogBlockchain->tx_hash }}"
                                            target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>
                                            {{ substr($record->latestLogBlockchain->tx_hash, 0, 8) }}...{{ substr($record->latestLogBlockchain->tx_hash, -6) }}
                                        </a>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-info btn-sm text-white me-1 btn-dokter-detail"
                                        data-record-id="{{ $record->id }}">
                                        <i class="bi bi-eye me-1"></i> Detail
                                    </button>

                                    @if ($canEdit)
                                        <a href="{{ route('dokter.records.edit', $record) }}" class="btn btn-warning btn-sm text-white">
                                            <i class="bi bi-pencil-square me-1"></i> Edit
                                        </a>
                                    @else
                                        <span class="badge bg-secondary" title="Diperlukan izin aktif pasien &amp; registrasi on-chain">
                                            @if (!$doctor->is_registered_onchain)
                                                Belum On-Chain
                                            @elseif (!$isPatientOnchain)
                                                Pasien Belum On-Chain
                                            @else
                                                Izin Dicabut
                                            @endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <script id="dokterRecordsJson"
                type="application/json">{!! json_encode($recordsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="modalDokterRecordDetail" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold" id="dokterDetailModalTitle">Detail Rekam Medis</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Tutup"></button>
                </div>
                <div class="modal-body small">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><strong>Tanggal Pemeriksaan:</strong> <span id="dokterDetailDate">-</span>
                        </div>
                        <div class="col-md-6"><strong>Pasien:</strong> <span id="dokterDetailPatient">-</span></div>
                        <div class="col-12"><strong>Dokter Terakhir:</strong> <span id="dokterDetailDoctor">-</span></div>
                    </div>
                    <hr>
                    <p class="mb-2"><strong>Keluhan:</strong><br><span id="dokterDetailComplaint">-</span></p>
                    <p class="mb-2"><strong>Diagnosis:</strong><br><span id="dokterDetailDiagnosis">-</span></p>
                    <p class="mb-2"><strong>Tindakan &amp; Obat:</strong><br><span id="dokterDetailTreatment">-</span></p>
                    <p class="mb-3"><strong>Catatan Tambahan:</strong><br><span id="dokterDetailNotes">-</span></p>
                    <div class="mb-3">
                        <strong>Canonical SHA-256 Hash:</strong><br>
                        <span id="dokterDetailHash" class="hash-code">-</span>
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
                                <tbody id="dokterDetailLogsBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endpush