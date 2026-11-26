<?php

use App\Models\Authorization;
use App\Models\Doctor;
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

    expect($patient->date_of_birth)->toBeInstanceOf(DateTimeInterface::class)
        ->and($patient->date_of_birth->toDateString())->toBe('1995-08-17');
    expect($patient->user->is($patientUser))->toBeTrue();
    expect($patient->doctors)->toHaveCount(2);
    expect($patient->authorizedDoctors)->toHaveCount(1)
        ->and($patient->authorizedDoctors->first()->is($authorizedDoctor))->toBeTrue();
    expect($patient->authorizations)->toHaveCount(2);
    expect($patient->medicalRecords)->toHaveCount(1)
        ->and($patient->medicalRecords->first()->is($record))->toBeTrue();
    expect(CarbonImmutable::instance($patient->date_of_birth)->year)->toBe(1995);
});
