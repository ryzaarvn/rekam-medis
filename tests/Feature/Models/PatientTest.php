<?php

use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts date of birth to a date instance and resolves authorized doctors and records', function () {
    $patientUser = User::create([
        'username' => 'pasien_ratna',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $doctorUserOne = User::create([
        'username' => 'dr_aktif',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);
    $doctorUserTwo = User::create([
        'username' => 'dr_cabut',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xc3d4e5f60718293a4b5c6d7e8f90123456789012',
    ]);

    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Ratna Sari',
        'date_of_birth' => '1995-08-17',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Diponegoro No. 5',
    ]);
    $authorizedDoctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUserOne->id,
        'full_name' => 'dr. Aktif',
        'specialization' => 'Saraf',
        'str_number' => 'STR-2026-0020',
    ]);
    $revokedDoctor = Doctor::create([
        'doctor_code' => 'DOC002',
        'user_id' => $doctorUserTwo->id,
        'full_name' => 'dr. Dicabut',
        'specialization' => 'Anak',
        'str_number' => 'STR-2026-0021',
    ]);

    Authorization::create([
        'patient_id' => $patient->id,
        'doctor_id' => $authorizedDoctor->id,
        'is_authorized' => true,
        'tx_hash' => '0x'.str_repeat('1', 64),
    ]);
    Authorization::create([
        'patient_id' => $patient->id,
        'doctor_id' => $revokedDoctor->id,
        'is_authorized' => false,
        'tx_hash' => '0x'.str_repeat('2', 64),
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $patient->id,
        'doctor_id' => $authorizedDoctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Sakit kepala',
        'diagnosis' => 'Migrain',
        'treatment' => 'Sumatriptan',
        'current_hash' => '0x'.str_repeat('3', 64),
    ]);
    $blockchainLog = LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $authorizedDoctor->id,
        'onchain_hash' => '0x'.str_repeat('3', 64),
        'tx_hash' => '0x'.str_repeat('4', 64),
        'block_number' => 7000150,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    expect($patient->date_of_birth)->toBeInstanceOf(DateTimeInterface::class)
        ->and($patient->date_of_birth->toDateString())->toBe('1995-08-17');
    expect($patient->user->is($patientUser))->toBeTrue();
    expect($patient->doctors)->toHaveCount(2);
    expect($patient->authorizedDoctors)->toHaveCount(1)
        ->and($patient->authorizedDoctors->first()->is($authorizedDoctor))->toBeTrue();
    expect($patient->authorizations)->toHaveCount(2);
    expect($patient->medicalRecords)->toHaveCount(1)
        ->and($patient->medicalRecords->first()->is($record))->toBeTrue();
    expect($patient->logBlockchains)->toHaveCount(1)
        ->and($patient->logBlockchains->first()->is($blockchainLog))->toBeTrue();
    expect(CarbonImmutable::instance($patient->date_of_birth)->year)->toBe(1995);
});

it('casts on-chain registration flag, normalizes registration tx hash, and scopes registered patients', function () {
    $registeredUser = User::create([
        'username' => 'pasien_onchain',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $pendingUser = User::create([
        'username' => 'pasien_pending',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);

    $registeredPatient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $registeredUser->id,
        'full_name' => 'Pasien Terdaftar',
        'date_of_birth' => '1994-05-10',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Merdeka No. 1',
        'is_registered_onchain' => 1,
        'registration_tx_hash' => '  0x'.str_repeat('A', 64).'  ',
    ]);

    $pendingPatient = Patient::create([
        'patient_code' => 'PAT002',
        'user_id' => $pendingUser->id,
        'full_name' => 'Pasien Pending',
        'date_of_birth' => '1996-06-12',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Merdeka No. 2',
        'is_registered_onchain' => 0,
        'registration_tx_hash' => '   ',
    ]);

    $scopedPatients = Patient::registeredOnchain()->get();

    expect($registeredPatient->is_registered_onchain)->toBeTrue()
        ->and($registeredPatient->registration_tx_hash)->toBe('0x'.str_repeat('a', 64))
        ->and($registeredPatient->registration_tx_hash)->toMatch('/^0x[a-f0-9]{64}$/');
    expect($pendingPatient->is_registered_onchain)->toBeFalse()
        ->and($pendingPatient->registration_tx_hash)->toBeNull();
    expect($scopedPatients)->toHaveCount(1)
        ->and($scopedPatients->first()->is($registeredPatient))->toBeTrue();
});
