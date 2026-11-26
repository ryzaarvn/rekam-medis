<?php

use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('disables updated_at, casts fields, normalizes hashes, and links record and doctor', function () {
    $doctorUser = User::create([
        'username' => 'dr_hendra',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $patientUser = User::create([
        'username' => 'pasien_eka',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);

    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Hendra Wijaya',
        'specialization' => 'THT',
        'str_number' => 'STR-2026-0050',
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Eka Putra',
        'date_of_birth' => '1989-07-09',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Gatot Subroto No. 3',
    ]);
    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Radang tenggorokan',
        'diagnosis' => 'Faringitis akut',
        'treatment' => 'Obat antiinflamasi',
        'current_hash' => '0x'.str_repeat('D', 64),
    ]);

    $log = LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $doctor->id,
        'onchain_hash' => '0x'.str_repeat('D', 64),
        'tx_hash' => '0x'.str_repeat('E', 64),
        'block_number' => '7123456',
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    expect(LogBlockchain::UPDATED_AT)->toBeNull();
    expect($log->getUpdatedAtColumn())->toBeNull();
    expect($log->block_number)->toBe(7123456);
    expect($log->created_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($log->onchain_hash)->toBe('0x'.str_repeat('d', 64))
        ->and($log->onchain_hash)->toMatch('/^0x[a-f0-9]{64}$/');
    expect($log->tx_hash)->toBe('0x'.str_repeat('e', 64))
        ->and($log->tx_hash)->toMatch('/^0x[a-f0-9]{64}$/');
    expect($log->medicalRecord->is($record))->toBeTrue();
    expect($log->doctor->is($doctor))->toBeTrue();
});
