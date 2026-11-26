<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('normalizes wallet addresses to lowercase and hashes passwords on creation', function () {
    $user = User::create([
        'username' => 'admin_utama',
        'email' => 'admin@klinik.test',
        'password' => 'secret-password',
        'role' => User::ROLE_ADMIN,
        'wallet_address' => '  0xA1B2C3D4E5F60718293A4B5C6D7E8F9012345678  ',
    ]);

    expect($user->wallet_address)->toBe('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678')
        ->and($user->wallet_address)->toMatch('/^0x[a-f0-9]{40}$/');
    expect(Hash::check('secret-password', $user->password))->toBeTrue();
    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token']);
});

it('identifies user roles accurately through helper methods', function (string $role, bool $admin, bool $doctor, bool $patient) {
    $user = new User(['role' => $role]);

    expect($user->isAdmin())->toBe($admin);
    expect($user->isDoctor())->toBe($doctor);
    expect($user->isPatient())->toBe($patient);
})->with([
    'admin role' => [User::ROLE_ADMIN, true, false, false],
    'dokter role' => [User::ROLE_DOKTER, false, true, false],
    'pasien role' => [User::ROLE_PASIEN, false, false, true],
]);

it('loads linked doctor and patient profiles from a user', function () {
    $doctorUser = User::create([
        'username' => 'dr_andi',
        'email' => null,
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $patientUser = User::create([
        'username' => 'pasien_budi',
        'email' => null,
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);

    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Andi Wijaya',
        'specialization' => 'Penyakit Dalam',
        'str_number' => 'STR-2026-0001',
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Budi Santoso',
        'date_of_birth' => '1992-05-14',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Merdeka No. 10',
    ]);

    expect($doctorUser->doctor->is($doctor))->toBeTrue();
    expect($doctorUser->patient)->toBeNull();
    expect($patientUser->patient->is($patient))->toBeTrue();
    expect($patientUser->doctor)->toBeNull();
});
