@extends('layouts.dashboard')

@section('title', 'Data Pasien')
@section('page', 'admin-patients')
@section('topbar_title', 'Panel Administrator')
@section('user_display', 'Admin ('.auth()->user()->username.')')

@section('sidebar')
    @include('admin.partials.sidebar')
@endsection

@section('content')
    <div class="page-header">
        <h1 class="page-title"><i class="bi bi-people"></i> Data Pasien</h1>
        <button type="button" class="btn btn-success btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#modalPatientCreate">
            <i class="bi bi-plus-lg me-1"></i> Tambah Data Pasien
        </button>
    </div>

    <div class="panel-card">
        <div class="table-responsive">
            <table class="table table-custom mb-0" id="tabelPasien">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>ID Pasien</th>
                        <th>Nama Pasien</th>
                        <th>Username</th>
                        <th>Alamat Wallet</th>
                        <th>Tanggal Lahir</th>
                        <th>Jenis Kelamin</th>
                        <th>Alamat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($patients as $index => $patient)
                        <tr>
                            <td>{{ $index + 1 }}.</td>
                            <td><strong>{{ $patient->patient_code }}</strong></td>
                            <td>{{ $patient->full_name }}</td>
                            <td>{{ $patient->user->username }}</td>
                            <td>
                                <span class="hash-code" title="{{ $patient->user->wallet_address }}">
                                    {{ substr($patient->user->wallet_address, 0, 8) }}...{{ substr($patient->user->wallet_address, -6) }}
                                </span>
                            </td>
                            <td class="text-nowrap">{{ $patient->date_of_birth?->toDateString() }}</td>
                            <td>{{ $patient->gender }}</td>
                            <td>{{ $patient->address }}</td>
                            <td class="text-nowrap">
                                <button
                                    type="button"
                                    class="btn btn-warning btn-icon text-white btn-edit-patient"
                                    title="Edit Pasien"
                                    data-update-url="{{ route('admin.patients.update', $patient) }}"
                                    data-patient-code="{{ $patient->patient_code }}"
                                    data-full-name="{{ $patient->full_name }}"
                                    data-date-of-birth="{{ $patient->date_of_birth?->toDateString() }}"
                                    data-gender="{{ $patient->gender }}"
                                    data-address="{{ $patient->address }}"
                                    data-username="{{ $patient->user->username }}"
                                    data-email="{{ $patient->user->email }}"
                                    data-wallet-address="{{ $patient->user->wallet_address }}"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </button>

                                <form
                                    method="POST"
                                    action="{{ route('admin.patients.destroy', $patient) }}"
                                    class="d-inline"
                                    data-confirm="Peringatan: Apakah Anda yakin ingin menghapus data pasien {{ $patient->full_name }}?"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-icon" title="Hapus Pasien">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                Belum ada data pasien terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="modalPatientCreate" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold">Tambah Data Pasien &amp; Akun Login</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form method="POST" action="{{ route('admin.patients.store') }}">
                    @csrf
                    <input type="hidden" name="_form_mode" value="create_patient">
                    <div class="modal-body small">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">ID Pasien <span class="text-muted">(Opsional)</span></label>
                                <input type="text" name="patient_code" value="{{ old('_form_mode') === 'create_patient' ? old('patient_code') : '' }}" class="form-control form-control-sm" placeholder="Otomatis (mis. PAT001)">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Nama Lengkap</label>
                                <input type="text" name="full_name" value="{{ old('_form_mode') === 'create_patient' ? old('full_name') : '' }}" class="form-control form-control-sm" placeholder="Contoh: Budi Santoso" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Tanggal Lahir</label>
                                <input type="date" name="date_of_birth" value="{{ old('_form_mode') === 'create_patient' ? old('date_of_birth') : '' }}" class="form-control form-control-sm" max="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Jenis Kelamin</label>
                                <select name="gender" class="form-select form-select-sm" required>
                                    <option value="">-- Pilih Jenis Kelamin --</option>
                                    <option value="Laki-laki" @selected(old('_form_mode') === 'create_patient' && old('gender') === 'Laki-laki')>Laki-laki</option>
                                    <option value="Perempuan" @selected(old('_form_mode') === 'create_patient' && old('gender') === 'Perempuan')>Perempuan</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Alamat Lengkap</label>
                                <textarea name="address" class="form-control form-control-sm" rows="2" placeholder="Masukkan alamat lengkap" required>{{ old('_form_mode') === 'create_patient' ? old('address') : '' }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Username Login</label>
                                <input type="text" name="username" value="{{ old('_form_mode') === 'create_patient' ? old('username') : '' }}" class="form-control form-control-sm" placeholder="pasien_budi" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Email <span class="text-muted">(Opsional)</span></label>
                                <input type="email" name="email" value="{{ old('_form_mode') === 'create_patient' ? old('email') : '' }}" class="form-control form-control-sm" placeholder="pasien@email.id">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Password Awal</label>
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="Minimal 8 karakter" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Alamat Wallet Ethereum Sepolia (MetaMask)</label>
                                <input type="text" name="wallet_address" value="{{ old('_form_mode') === 'create_patient' ? old('wallet_address') : '' }}" class="form-control form-control-sm font-monospace" placeholder="0x..." required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan Data Pasien</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalPatientEdit" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold">Edit Data Pasien</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form id="formPatientEdit" method="POST" action="{{ old('_edit_action', '') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_form_mode" value="edit_patient">
                    <input type="hidden" name="_edit_action" id="editPatActionInput" value="{{ old('_edit_action', '') }}">
                    <div class="modal-body small">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">ID Pasien</label>
                                <input type="text" name="patient_code" id="editPatCode" value="{{ old('_form_mode') === 'edit_patient' ? old('patient_code') : '' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Nama Lengkap</label>
                                <input type="text" name="full_name" id="editPatFullName" value="{{ old('_form_mode') === 'edit_patient' ? old('full_name') : '' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Tanggal Lahir</label>
                                <input type="date" name="date_of_birth" id="editPatDob" value="{{ old('_form_mode') === 'edit_patient' ? old('date_of_birth') : '' }}" class="form-control form-control-sm" max="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Jenis Kelamin</label>
                                <select name="gender" id="editPatGender" class="form-select form-select-sm" required>
                                    <option value="Laki-laki" @selected(old('_form_mode') === 'edit_patient' && old('gender') === 'Laki-laki')>Laki-laki</option>
                                    <option value="Perempuan" @selected(old('_form_mode') === 'edit_patient' && old('gender') === 'Perempuan')>Perempuan</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Alamat Lengkap</label>
                                <textarea name="address" id="editPatAddress" class="form-control form-control-sm" rows="2" required>{{ old('_form_mode') === 'edit_patient' ? old('address') : '' }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Username Login</label>
                                <input type="text" name="username" id="editPatUsername" value="{{ old('_form_mode') === 'edit_patient' ? old('username') : '' }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Email <span class="text-muted">(Opsional)</span></label>
                                <input type="email" name="email" id="editPatEmail" value="{{ old('_form_mode') === 'edit_patient' ? old('email') : '' }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Password Baru <span class="text-muted">(Opsional)</span></label>
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="Kosongkan jika tetap">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Alamat Wallet Ethereum Sepolia (MetaMask)</label>
                                <input type="text" name="wallet_address" id="editPatWallet" value="{{ old('_form_mode') === 'edit_patient' ? old('wallet_address') : '' }}" class="form-control form-control-sm font-monospace" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
    @if ($errors->any() && old('_form_mode') === 'create_patient')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                new bootstrap.Modal(document.getElementById('modalPatientCreate')).show();
            });
        </script>
    @elseif ($errors->any() && old('_form_mode') === 'edit_patient')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                new bootstrap.Modal(document.getElementById('modalPatientEdit')).show();
            });
        </script>
    @endif
@endpush
