@extends('layouts.dashboard')

@section('title', 'Data Dokter')
@section('page', 'admin-doctors')
@section('topbar_title', 'Panel Administrator')
@section('user_display', 'Admin')

@section('sidebar')
    @include('admin.partials.sidebar')
@endsection

@section('content')
    <div class="page-header">
        <h1 class="page-title">Data Dokter</h1>
        <button type="button" class="btn btn-success btn-sm fw-semibold" data-bs-toggle="modal"
            data-bs-target="#modalDoctorCreate">
            <i class="bi bi-plus-lg me-1"></i> Tambah Data Dokter
        </button>
    </div>

    <div class="panel-card">
        <div class="table-responsive">
            <table class="table table-custom mb-0" id="tabelDokter">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>ID Dokter</th>
                        <th>Nama Dokter</th>
                        <th>Spesialis</th>
                        <th>No. STR</th>
                        <th>Username &amp; Wallet</th>
                        <th>Status On-Chain</th>
                        <th>Tx Registrasi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($doctors as $index => $doctor)
                        <tr>
                            <td>{{ $index + 1 }}.</td>
                            <td><strong>{{ $doctor->doctor_code }}</strong></td>
                            <td>{{ $doctor->full_name }}</td>
                            <td>{{ $doctor->specialization }}</td>
                            <td>{{ $doctor->str_number }}</td>
                            <td>
                                <div class="small fw-semibold">{{ $doctor->user->username }}</div>
                                <span class="hash-code" title="{{ $doctor->user->wallet_address }}">
                                    {{ substr($doctor->user->wallet_address, 0, 8) }}...{{ substr($doctor->user->wallet_address, -6) }}
                                </span>
                            </td>
                            <td>
                                @if ($doctor->is_registered_onchain)
                                    <span class="badge bg-success">Terdaftar</span>
                                @elseif ($doctor->registration_tx_hash !== null)
                                    <span class="badge bg-warning text-dark">Dinonaktifkan</span>
                                @else
                                    <span class="badge bg-secondary">Belum Terdaftar</span>
                                @endif
                            </td>
                            <td>
                                @if ($doctor->registration_tx_hash !== null)
                                    <a href="https://sepolia.etherscan.io/tx/{{ $doctor->registration_tx_hash }}" target="_blank"
                                        rel="noopener noreferrer" class="btn btn-outline-primary btn-sm"
                                        title="{{ $doctor->registration_tx_hash }}">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>
                                        {{ substr($doctor->registration_tx_hash, 0, 8) }}...{{ substr($doctor->registration_tx_hash, -6) }}
                                    </a>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                @if ($doctor->is_registered_onchain)
                                    <button type="button" class="btn btn-outline-danger btn-sm me-1 btn-deactivate-onchain"
                                        data-url="{{ route('admin.doctors.onchain-deactivate', $doctor) }}"
                                        data-doctor-wallet="{{ $doctor->user->wallet_address }}"
                                        data-doctor-name="{{ $doctor->full_name }}">
                                        <i class="bi bi-slash-circle me-1"></i> Nonaktifkan On-Chain
                                    </button>
                                @else
                                    <button type="button" class="btn btn-primary btn-sm me-1 btn-register-onchain"
                                        data-url="{{ route('admin.doctors.onchain-register', $doctor) }}"
                                        data-doctor-wallet="{{ $doctor->user->wallet_address }}"
                                        data-doctor-name="{{ $doctor->full_name }}">
                                        <i class="bi bi-link-45deg me-1"></i> Daftarkan On-Chain
                                    </button>
                                @endif

                                <button type="button" class="btn btn-warning btn-icon text-white btn-edit-doctor"
                                    title="Edit Dokter" data-update-url="{{ route('admin.doctors.update', $doctor) }}"
                                    data-doctor-id="{{ $doctor->id }}" data-doctor-code="{{ $doctor->doctor_code }}"
                                    data-full-name="{{ $doctor->full_name }}"
                                    data-specialization="{{ $doctor->specialization }}"
                                    data-str-number="{{ $doctor->str_number }}" data-username="{{ $doctor->user->username }}"
                                    data-email="{{ $doctor->user->email }}"
                                    data-wallet-address="{{ $doctor->user->wallet_address }}">
                                    <i class="bi bi-pencil-square"></i>
                                </button>

                                <form method="POST" action="{{ route('admin.doctors.destroy', $doctor) }}" class="d-inline"
                                    data-confirm="Peringatan: Apakah Anda yakin ingin menghapus data dokter {{ $doctor->full_name }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-icon" title="Hapus Dokter">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                Belum ada data dokter terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="modalDoctorCreate" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold">Tambah Data Dokter &amp; Akun Login</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Tutup"></button>
                </div>
                <form method="POST" action="{{ route('admin.doctors.store') }}">
                    @csrf
                    <input type="hidden" name="_form_mode" value="create_doctor">
                    <div class="modal-body small">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">ID Dokter <span
                                        class="text-muted">(Opsional)</span></label>
                                <input type="text" name="doctor_code"
                                    value="{{ old('_form_mode') === 'create_doctor' ? old('doctor_code') : '' }}"
                                    class="form-control form-control-sm" placeholder="Otomatis (mis. DOC001)">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Nama Lengkap &amp; Gelar</label>
                                <input type="text" name="full_name"
                                    value="{{ old('_form_mode') === 'create_doctor' ? old('full_name') : '' }}"
                                    class="form-control form-control-sm" placeholder="Contoh: Dr. Andi Wijaya" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Spesialisasi</label>
                                <input type="text" name="specialization"
                                    value="{{ old('_form_mode') === 'create_doctor' ? old('specialization') : '' }}"
                                    class="form-control form-control-sm" placeholder="Contoh: Penyakit Dalam" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Nomor STR</label>
                                <input type="text" name="str_number"
                                    value="{{ old('_form_mode') === 'create_doctor' ? old('str_number') : '' }}"
                                    class="form-control form-control-sm" placeholder="Contoh: STR-2026-0001" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Username Login</label>
                                <input type="text" name="username"
                                    value="{{ old('_form_mode') === 'create_doctor' ? old('username') : '' }}"
                                    class="form-control form-control-sm" placeholder="dr_andi" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Email <span
                                        class="text-muted">(Opsional)</span></label>
                                <input type="email" name="email"
                                    value="{{ old('_form_mode') === 'create_doctor' ? old('email') : '' }}"
                                    class="form-control form-control-sm" placeholder="dokter@klinik.id">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Password Awal</label>
                                <input type="password" name="password" class="form-control form-control-sm"
                                    placeholder="Minimal 8 karakter" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Alamat Wallet Ethereum Sepolia (MetaMask)</label>
                                <input type="text" name="wallet_address"
                                    value="{{ old('_form_mode') === 'create_doctor' ? old('wallet_address') : '' }}"
                                    class="form-control form-control-sm font-monospace" placeholder="0x..." required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan Data Dokter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDoctorEdit" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold">Edit Data Dokter</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Tutup"></button>
                </div>
                <form id="formDoctorEdit" method="POST" action="{{ old('_edit_action', '') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_form_mode" value="edit_doctor">
                    <input type="hidden" name="_edit_action" id="editDocActionInput" value="{{ old('_edit_action', '') }}">
                    <div class="modal-body small">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">ID Dokter</label>
                                <input type="text" name="doctor_code" id="editDocCode"
                                    value="{{ old('_form_mode') === 'edit_doctor' ? old('doctor_code') : '' }}"
                                    class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Nama Lengkap &amp; Gelar</label>
                                <input type="text" name="full_name" id="editDocFullName"
                                    value="{{ old('_form_mode') === 'edit_doctor' ? old('full_name') : '' }}"
                                    class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Spesialisasi</label>
                                <input type="text" name="specialization" id="editDocSpecialization"
                                    value="{{ old('_form_mode') === 'edit_doctor' ? old('specialization') : '' }}"
                                    class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Nomor STR</label>
                                <input type="text" name="str_number" id="editDocStrNumber"
                                    value="{{ old('_form_mode') === 'edit_doctor' ? old('str_number') : '' }}"
                                    class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Username Login</label>
                                <input type="text" name="username" id="editDocUsername"
                                    value="{{ old('_form_mode') === 'edit_doctor' ? old('username') : '' }}"
                                    class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Email <span
                                        class="text-muted">(Opsional)</span></label>
                                <input type="email" name="email" id="editDocEmail"
                                    value="{{ old('_form_mode') === 'edit_doctor' ? old('email') : '' }}"
                                    class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Password Baru <span
                                        class="text-muted">(Opsional)</span></label>
                                <input type="password" name="password" class="form-control form-control-sm"
                                    placeholder="Kosongkan jika tetap">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Alamat Wallet Ethereum Sepolia (MetaMask)</label>
                                <input type="text" name="wallet_address" id="editDocWallet"
                                    value="{{ old('_form_mode') === 'edit_doctor' ? old('wallet_address') : '' }}"
                                    class="form-control form-control-sm font-monospace" required>
                                <div class="form-text">Jika wallet diubah, status registrasi on-chain akan direset agar
                                    dapat didaftarkan ulang.</div>
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
    @if ($errors->any() && old('_form_mode') === 'create_doctor')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                new bootstrap.Modal(document.getElementById('modalDoctorCreate')).show();
            });
        </script>
    @elseif ($errors->any() && old('_form_mode') === 'edit_doctor')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                new bootstrap.Modal(document.getElementById('modalDoctorEdit')).show();
            });
        </script>
    @endif
@endpush