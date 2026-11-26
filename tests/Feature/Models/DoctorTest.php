<?php

use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts registration flag and normalizes registration tx hash to lowercase', function () {
    $user = User::create([
        'username' => 'dr_sinta',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);

    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $user->id,
        'full_name' => 'dr. Sinta Dewi',
        'specialization' => 'Kardiologi',
        'str_number' => 'STR-2026-0002',
        'is_registered_onchain' => 1,
        'registration_tx_hash' => '0x'.str_repeat('A', 64),
    ]);

    expect($doctor->is_registered_onchain)->toBeTrue();
    expect($doctor->registration_tx_hash)->toBe('0x'.str_repeat('a', 64))
        ->and($doctor->registration_tx_hash)->toMatch('/^0x[a-f0-9]{64}$/');
});

it('filters doctors registered on-chain and resolves authorized patients and logs', function () {
    $registeredUser = User::create([
        'username' => 'dr_terdaftar',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $unregisteredUser = User::create([
        'username' => 'dr_pending',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);
    $patientUserOne = User::create([
        'username' => 'pasien_satu',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xc3d4e5f60718293a4b5c6d7e8f90123456789012',
    ]);
    $patientUserTwo = User::create([
        'username' => 'pasien_dua',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xd4e5f60718293a4b5c6d7e8f9012345678901234',
    ]);

    $registeredDoctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $registeredUser->id,
        'full_name' => 'dr. Terdaftar',
        'specialization' => 'Umum',
        'str_number' => 'STR-2026-0010',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('1', 64),
    ]);
    Doctor::create([
        'doctor_code' => 'DOC002',
        'user_id' => $unregisteredUser->id,
        'full_name' => 'dr. Pending',
        'specialization' => 'Umum',
        'str_number' => 'STR-2026-0011',
        'is_registered_onchain' => false,
    ]);

    $authorizedPatient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUserOne->id,
        'full_name' => 'Pasien Satu',
        'date_of_birth' => '1990-01-01',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Sudirman No. 1',
    ]);
    $revokedPatient = Patient::create([
        'patient_code' => 'PAT002',
        'user_id' => $patientUserTwo->id,
        'full_name' => 'Pasien Dua',
        'date_of_birth' => '1991-02-02',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Sudirman No. 2',
    ]);

    Authorization::create([
        'patient_id' => $authorizedPatient->id,
        'doctor_id' => $registeredDoctor->id,
        'is_authorized' => true,
        'tx_hash' => '0x'.str_repeat('2', 64),
    ]);
    Authorization::create([
        'patient_id' => $revokedPatient->id,
        'doctor_id' => $registeredDoctor->id,
        'is_authorized' => false,
        'tx_hash' => '0x'.str_repeat('3', 64),
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $authorizedPatient->id,
        'doctor_id' => $registeredDoctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Istirahat dan paracetamol',
        'current_hash' => '0x'.str_repeat('4', 64),
    ]);
    $log = LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $registeredDoctor->id,
        'onchain_hash' => '0x'.str_repeat('4', 64),
        'tx_hash' => '0x'.str_repeat('5', 64),
        'block_number' => 7000100,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    $scopedDoctors = Doctor::registeredOnchain()->get();

    expect($scopedDoctors)->toHaveCount(1)
        ->and($scopedDoctors->first()->is($registeredDoctor))->toBeTrue();
    expect($registeredDoctor->user->is($registeredUser))->toBeTrue();
    expect($registeredDoctor->patients)->toHaveCount(2);
    expect($registeredDoctor->authorizedPatients)->toHaveCount(1)
        ->and($registeredDoctor->authorizedPatients->first()->is($authorizedPatient))->toBeTrue();
    expect($registeredDoctor->authorizations)->toHaveCount(2);
    expect($registeredDoctor->medicalRecords)->toHaveCount(1)
        ->and($registeredDoctor->medicalRecords->first()->is($record))->toBeTrue();
    expect($registeredDoctor->logBlockchains)->toHaveCount(1)
        ->and($registeredDoctor->logBlockchains->first()->is($log))->toBeTrue();
});
