<?php

use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts authorization state, normalizes tx hash, and scopes active authorizations', function () {
    $doctorUserOne = User::create([
        'username' => 'dr_satu',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $doctorUserTwo = User::create([
        'username' => 'dr_dua',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);
    $patientUser = User::create([
        'username' => 'pasien_dewi',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xc3d4e5f60718293a4b5c6d7e8f90123456789012',
    ]);

    $doctorOne = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUserOne->id,
        'full_name' => 'dr. Satu',
        'specialization' => 'Umum',
        'str_number' => 'STR-2026-0040',
    ]);
    $doctorTwo = Doctor::create([
        'doctor_code' => 'DOC002',
        'user_id' => $doctorUserTwo->id,
        'full_name' => 'dr. Dua',
        'specialization' => 'Mata',
        'str_number' => 'STR-2026-0041',
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Dewi Kartika',
        'date_of_birth' => '1993-11-05',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Asia Afrika No. 8',
    ]);

    $activeAuthorization = Authorization::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctorOne->id,
        'is_authorized' => 1,
        'tx_hash' => '0x'.str_repeat('C', 64),
    ]);
    $revokedAuthorization = Authorization::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctorTwo->id,
        'is_authorized' => 0,
        'tx_hash' => null,
    ]);

    $authorizedRecords = Authorization::authorized()->get();

    expect($activeAuthorization->is_authorized)->toBeTrue();
    expect($activeAuthorization->tx_hash)->toBe('0x'.str_repeat('c', 64))
        ->and($activeAuthorization->tx_hash)->toMatch('/^0x[a-f0-9]{64}$/');
    expect($revokedAuthorization->is_authorized)->toBeFalse();
    expect($revokedAuthorization->tx_hash)->toBeNull();
    expect($authorizedRecords)->toHaveCount(1)
        ->and($authorizedRecords->first()->is($activeAuthorization))->toBeTrue();
    expect($activeAuthorization->patient->is($patient))->toBeTrue();
    expect($activeAuthorization->doctor->is($doctorOne))->toBeTrue();
});
