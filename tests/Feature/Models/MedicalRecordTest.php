<?php

use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts record date, normalizes current hash, and resolves latest blockchain log', function () {
    $doctorUser = User::create([
        'username' => 'dr_bayu',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $patientUser = User::create([
        'username' => 'pasien_citra',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);

    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Bayu Pratama',
        'specialization' => 'Bedah',
        'str_number' => 'STR-2026-0030',
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Citra Lestari',
        'date_of_birth' => '1998-03-21',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Ahmad Yani No. 12',
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Nyeri lutut',
        'diagnosis' => 'Cedera ligamen ringan',
        'treatment' => 'Fisioterapi',
        'notes' => null,
        'current_hash' => '0x'.str_repeat('B', 64),
    ]);

    LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $doctor->id,
        'onchain_hash' => '0x'.str_repeat('a', 64),
        'tx_hash' => '0x'.str_repeat('1', 64),
        'block_number' => 7000010,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);
    $latestLog = LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $doctor->id,
        'onchain_hash' => '0x'.str_repeat('b', 64),
        'tx_hash' => '0x'.str_repeat('2', 64),
        'block_number' => 7000025,
        'action_type' => LogBlockchain::ACTION_UPDATE,
    ]);

    expect($record->record_date)->toBeInstanceOf(DateTimeInterface::class)
        ->and($record->record_date->toDateString())->toBe('2026-11-26');
    expect($record->current_hash)->toBe('0x'.str_repeat('b', 64))
        ->and($record->current_hash)->toMatch('/^0x[a-f0-9]{64}$/');
    expect($record->patient->is($patient))->toBeTrue();
    expect($record->doctor->is($doctor))->toBeTrue();
    expect($record->logBlockchains)->toHaveCount(2);
    expect($record->latestLogBlockchain->is($latestLog))->toBeTrue();
});
