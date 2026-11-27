<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the login page for guests without public registration link', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('E-REKAM MEDIS')
        ->assertSee('Belum memiliki akun? Hubungi Admin untuk pendaftaran.')
        ->assertDontSee('Daftar akun pasien di sini');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Masuk')
        ->assertSee('Belum memiliki akun? Hubungi Admin untuk pendaftaran.')
        ->assertDontSee('Daftar akun pasien di sini');
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

it('returns 404 when accessing GET /register', function () {
    $this->get('/register')->assertNotFound();
});

it('returns 404 when submitting POST /register', function () {
    $this->post('/register', [
        'full_name' => 'Budi Santoso',
        'username' => 'budi_santoso',
        'date_of_birth' => '1998-05-14',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Merdeka No. 12, Jakarta',
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['username' => 'budi_santoso']);
    $this->assertGuest();
});

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
