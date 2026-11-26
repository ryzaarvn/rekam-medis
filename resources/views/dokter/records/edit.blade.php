@extends('layouts.dashboard')

@section('title', 'Edit Rekam Medis '.$medicalRecord->record_code)
@section('page', 'dokter-record-edit')
@section('topbar_title', 'Portal Dokter')
@section('user_display', $doctor->full_name.' ('.$doctor->doctor_code.')')

@section('sidebar')
    @include('dokter.partials.sidebar')
@endsection

@section('content')
    <div style="max-width: 780px; margin: 0 auto;">
        @if (! $doctor->is_registered_onchain)
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Akun Belum Terdaftar On-Chain:</strong> Admin belum mendaftarkan alamat wallet Anda ke smart contract Sepolia.
            </div>
        @endif

        @if (! $medicalRecord->patient->is_registered_onchain)
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Pasien Belum Terdaftar On-Chain:</strong> Wallet pasien belum terdaftar/aktif di blockchain Sepolia. Hubungi Admin sebelum memperbarui rekam medis ini.
            </div>
        @endif

        <div id="dokterRecordAlert" class="alert d-none fs-6" role="alert"></div>

        <div class="page-header">
            <h1 class="page-title"><i class="bi bi-pencil-square"></i> Edit Rekam Medis ({{ $medicalRecord->record_code }})</h1>
            <a href="{{ route('dokter.records.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Riwayat
            </a>
        </div>

        <div class="panel-card p-4 elder-form">
            <form
                id="dokterEditRecordForm"
                data-prepare-url="{{ route('dokter.records.prepare-update', $medicalRecord) }}"
                data-update-url="{{ route('dokter.records.update', $medicalRecord) }}"
            >
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-upc-scan me-1 text-success"></i> ID Rekam Medis</label>
                        <input type="text" class="form-control bg-light" value="{{ $medicalRecord->record_code }}" readonly>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><i class="bi bi-person me-1 text-success"></i> Nama Pasien</label>
                        <input
                            type="text"
                            class="form-control bg-light"
                            value="{{ $medicalRecord->patient->full_name }} ({{ $medicalRecord->patient->patient_code }})"
                            readonly
                        >
                    </div>
                    <div class="col-md-3">
                        <label for="record_date" class="form-label"><i class="bi bi-calendar-event me-1 text-success"></i> Tanggal</label>
                        <input
                            type="date"
                            name="record_date"
                            id="record_date"
                            value="{{ $medicalRecord->record_date?->toDateString() }}"
                            class="form-control"
                            required
                        >
                    </div>
                </div>

                <div class="mb-3">
                    <label for="complaint" class="form-label">
                        <i class="bi bi-chat-left-text me-1 text-success"></i> Keluhan Pasien
                    </label>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="quick-chip" data-quick-target="complaint" data-quick-text="Demam dan sakit kepala">+ Demam &amp; sakit kepala</button>
                        <button type="button" class="quick-chip" data-quick-target="complaint" data-quick-text="Batuk dan pilek">+ Batuk &amp; pilek</button>
                        <button type="button" class="quick-chip" data-quick-target="complaint" data-quick-text="Nyeri lambung / mual">+ Nyeri lambung</button>
                    </div>
                    <input
                        type="text"
                        name="complaint"
                        id="complaint"
                        value="{{ $medicalRecord->complaint }}"
                        class="form-control"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="diagnosis" class="form-label">
                        <i class="bi bi-clipboard2-pulse me-1 text-success"></i> Hasil Diagnosis
                    </label>
                    <input
                        type="text"
                        name="diagnosis"
                        id="diagnosis"
                        value="{{ $medicalRecord->diagnosis }}"
                        class="form-control"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="treatment" class="form-label">
                        <i class="bi bi-capsule me-1 text-success"></i> Tindakan &amp; Obat
                    </label>
                    <input
                        type="text"
                        name="treatment"
                        id="treatment"
                        value="{{ $medicalRecord->treatment }}"
                        class="form-control"
                        required
                    >
                </div>

                <div class="mb-4">
                    <label for="notes" class="form-label">
                        <i class="bi bi-pencil me-1 text-success"></i> Pesan untuk Pasien
                    </label>
                    <textarea
                        name="notes"
                        id="notes"
                        class="form-control"
                        rows="2"
                    >{{ $medicalRecord->notes }}</textarea>
                </div>

                <button
                    type="submit"
                    id="btnSubmitUpdateRecord"
                    class="btn btn-primary btn-lg w-100 fw-bold py-3"
                    @disabled(! $doctor->is_registered_onchain || ! $medicalRecord->patient->is_registered_onchain)
                >
                    <i class="bi bi-check-circle-fill me-2"></i> Perbarui Rekam Medis di Sepolia
                </button>
            </form>
        </div>
    </div>
@endsection
