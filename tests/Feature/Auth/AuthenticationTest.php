<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders login and registration pages for guests', function () {
    $this->get('/')->assertOk()->assertSee('E-REKAM MEDIS');
    $this->get(route('login'))->assertOk()->assertSee('Masuk ke Dashboard');
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('PENDAFTARAN PASIEN BARU')
        ->assertDontSee('value="dokter"', false);
});

it('authenticates users by username and redirects to each role dashboard', function (string $role, string $expectedRoute) {
    $user = User::factory()->create([
        'username' => 'user_'.$role,
        'password' => 'SecretPass123',
        'role' => $role,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);

    $response = $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'SecretPass123',
    ]);

    $response->assertRedirect(route($expectedRoute));
    $this->assertAuthenticatedAs($user);
})->with([
    'admin' => [User::ROLE_ADMIN, 'admin.dashboard'],
    'dokter' => [User::ROLE_DOKTER, 'dokter.dashboard'],
    'pasien' => [User::ROLE_PASIEN, 'pasien.dashboard'],
]);

it('rejects login with invalid credentials', function () {
    User::factory()->create([
        'username' => 'admin_test',
        'password' => 'CorrectPass123',
        'role' => User::ROLE_ADMIN,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);

    $response = $this->from(route('login'))->post(route('login.store'), [
        'username' => 'admin_test',
        'password' => 'WrongPassword',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');
    $this->assertGuest();
});

it('registers a patient atomically with normalized wallet and generated patient_code', function () {
    $response = $this->post(route('register.store'), [
        'full_name' => 'Budi Santoso',
        'username' => 'budi_santoso',
        'date_of_birth' => '1998-05-14',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Merdeka No. 12, Jakarta',
        'wallet_address' => '  0xB2C3D4E5F60718293A4B5C6D7E8F901234567890  ',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect(route('pasien.dashboard'));
    $this->assertAuthenticated();

    $user = User::query()->where('username', 'budi_santoso')->firstOrFail();
    $patient = Patient::query()->where('user_id', $user->id)->firstOrFail();

    expect($user->role)->toBe(User::ROLE_PASIEN)
        ->and($user->wallet_address)->toBe('0xb2c3d4e5f60718293a4b5c6d7e8f901234567890');
    expect($patient->patient_code)->toBe('PAT001')
        ->and($patient->gender)->toBe(Patient::GENDER_MALE);
});

it('prevents public registration from selecting doctor or admin roles', function (string $forbiddenRole) {
    $response = $this->from(route('register'))->post(route('register.store'), [
        'role' => $forbiddenRole,
        'full_name' => 'Penyusup',
        'username' => 'penyusup_'.$forbiddenRole,
        'date_of_birth' => '1995-01-01',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Uji Coba No. 1',
        'wallet_address' => '0xc3d4e5f60718293a4b5c6d7e8f90123456789012',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect(route('register'))
        ->assertSessionHasErrors('role');
    $this->assertDatabaseMissing('users', ['username' => 'penyusup_'.$forbiddenRole]);
    $this->assertGuest();
})->with([
    'dokter' => [User::ROLE_DOKTER],
    'admin' => [User::ROLE_ADMIN],
]);

it('logs an authenticated user out and clears the session', function () {
    $user = User::factory()->pasien()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

it('redirects guests to login and forbids mismatched roles across protected portals', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('dokter.dashboard'))->assertRedirect(route('login'));
    $this->get(route('pasien.dashboard'))->assertRedirect(route('login'));

    $admin = User::factory()->admin()->create();
    $dokterUser = User::factory()->dokter()->create();
    Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $dokterUser->id,
        'full_name' => 'dr. Andi',
        'specialization' => 'Umum',
        'str_number' => 'STR-001',
    ]);
    $pasienUser = User::factory()->pasien()->create();
    Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $pasienUser->id,
        'full_name' => 'Pasien Satu',
        'date_of_birth' => '1995-01-01',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Mawar',
    ]);

    $this->actingAs($pasienUser)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($pasienUser)->get(route('dokter.dashboard'))->assertForbidden();
    $this->actingAs($dokterUser)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($dokterUser)->get(route('pasien.dashboard'))->assertForbidden();
    $this->actingAs($admin)->get(route('dokter.dashboard'))->assertForbidden();
    $this->actingAs($admin)->get(route('pasien.dashboard'))->assertForbidden();
});
