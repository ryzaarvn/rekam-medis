@extends('layouts.dashboard')

@section('title', 'Catat Pemeriksaan')
@section('page', 'dokter-record-create')
@section('topbar_title', 'Panel Dokter')
@section('user_display', $doctor->full_name)

@section('sidebar')
    @include('dokter.partials.sidebar')
@endsection

@section('content')
    <div style="max-width: 780px; margin: 0 auto;">
        @if (!$doctor->is_registered_onchain)
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Akun Belum Terdaftar On-Chain:</strong> Admin belum mendaftarkan alamat wallet Anda ke smart contract
                Sepolia. Hubungi Admin sebelum mencatat rekam medis.
            </div>
        @endif

        @if ($authorizedPatients->isEmpty())
            <div class="alert alert-info" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i>
                Belum ada pasien terdaftar on-chain yang memberikan izin akses kepada Anda saat ini.
            </div>
        @endif

        <div id="dokterRecordAlert" class="alert d-none fs-6" role="alert"></div>

        <div class="page-header">
            <h1 class="page-title">Catatan Pemeriksaan Pasien</h1>
        </div>

        <div class="panel-card p-4 elder-form">
            <form id="dokterCreateRecordForm" data-prepare-url="{{ route('dokter.records.prepare') }}"
                data-store-url="{{ route('dokter.records.store') }}">
                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label for="patient_id" class="form-label">
                            <i class="bi bi-person me-1 text-success"></i> Nama Pasien (Terotorisasi)
                        </label>
                        <select name="patient_id" id="patient_id" class="form-select" required>
                            <option value="">-- Pilih Nama Pasien --</option>
                            @foreach ($authorizedPatients as $patient)
                                <option value="{{ $patient->id }}" data-wallet="{{ $patient->user->wallet_address }}"
                                    @selected((string) old('patient_id') === (string) $patient->id)>
                                    {{ $patient->full_name }} ({{ $patient->patient_code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="record_date" class="form-label">
                            <i class="bi bi-calendar-event me-1 text-success"></i> Tanggal Periksa
                        </label>
                        <input type="date" name="record_date" id="record_date"
                            value="{{ old('record_date', now()->toDateString()) }}" class="form-control" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="complaint" class="form-label">
                        <i class="bi bi-chat-left-text me-1 text-success"></i> Keluhan Pasien
                    </label>
                    <input type="text" name="complaint" id="complaint" class="form-control"
                        placeholder="Tulis keluhan pasien..." required>
                </div>

                <div class="mb-3">
                    <label for="diagnosis" class="form-label">
                        <i class="bi bi-clipboard2-pulse me-1 text-success"></i> Hasil Diagnosis
                    </label>
                    <input type="text" name="diagnosis" id="diagnosis" class="form-control"
                        placeholder="Tulis hasil diagnosis dokter..." required>
                </div>

                <div class="mb-3">
                    <label for="treatment" class="form-label">
                        <i class="bi bi-capsule me-1 text-success"></i> Tindakan &amp; Obat
                    </label>
                    <input type="text" name="treatment" id="treatment" class="form-control"
                        placeholder="Tulis tindakan atau obat yang diberikan..." required>
                </div>

                <div class="mb-4">
                    <label for="notes" class="form-label">
                        <i class="bi bi-pencil me-1 text-success"></i> Catatan
                    </label>
                    <textarea name="notes" id="notes" class="form-control" rows="2"
                        placeholder="Contoh: Istirahat cukup dan kontrol 3 hari lagi"></textarea>
                </div>

                <button type="submit" id="btnSubmitCreateRecord" class="btn btn-primary btn-lg w-100 fw-bold py-3"
                    @disabled(!$doctor->is_registered_onchain || $authorizedPatients->isEmpty())>
                    <i class="bi bi-check-circle-fill me-2"></i> Simpan Rekam Medis
                </button>
            </form>
        </div>
    </div>
@endsection