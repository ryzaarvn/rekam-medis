<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('redirects guests to login when accessing account endpoints', function (string $method, string $routeName) {
    $response = match ($method) {
        'GET' => $this->get(route($routeName)),
        'PUT' => $this->put(route($routeName), []),
    };

    $response->assertRedirect(route('login'));
})->with([
    'edit page' => ['GET', 'account.edit'],
    'credentials update' => ['PUT', 'account.credentials.update'],
    'password update' => ['PUT', 'account.password.update'],
]);

it('allows each role to access the account page and see their sidebar link, credentials, and profile summary', function (
    string $role,
    string $expectedPanelTitle,
    string $expectedUserDisplay,
    string $expectedRoleBadge,
    string $expectedDashboardRoute,
) {
    $wallet = match ($role) {
        User::ROLE_ADMIN => '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3',
        User::ROLE_DOKTER => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
        User::ROLE_PASIEN => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    };

    $user = User::factory()->create([
        'username' => 'akun_'.$role,
        'email' => null,
        'role' => $role,
        'wallet_address' => $wallet,
    ]);

    if ($role === User::ROLE_DOKTER) {
        Doctor::create([
            'doctor_code' => 'DOC101',
            'user_id' => $user->id,
            'full_name' => 'dr. Sari <script>alert(1)</script>',
            'specialization' => 'Kardiologi',
            'str_number' => 'STR-2026-101',
            'is_registered_onchain' => true,
            'registration_tx_hash' => '0x'.str_repeat('1', 64),
        ]);
    }

    if ($role === User::ROLE_PASIEN) {
        Patient::create([
            'patient_code' => 'PAT101',
            'user_id' => $user->id,
            'full_name' => 'Budi <script>alert(2)</script>',
            'date_of_birth' => '1994-05-10',
            'gender' => Patient::GENDER_MALE,
            'address' => 'Jl. Anggrek No. 12',
            'is_registered_onchain' => false,
        ]);
    }

    $this->actingAs($user)
        ->get(route($expectedDashboardRoute))
        ->assertOk()
        ->assertSee(route('account.edit'), false)
        ->assertSee('Akun');

    $response = $this->actingAs($user)->get(route('account.edit'));

    $response->assertOk()
        ->assertSee($expectedPanelTitle)
        ->assertSee($expectedUserDisplay)
        ->assertSee($expectedRoleBadge)
        ->assertSee($user->username)
        ->assertSee($wallet)
        ->assertSee('<a href="'.route('account.edit').'" class="active">', false)
        ->assertSee('bi-person-circle', false)
        ->assertSee('name="password"', false)
        ->assertSee('name="password_confirmation"', false)
        ->assertDontSee('name="current_password"', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<script>alert(2)</script>', false);

    if ($role === User::ROLE_DOKTER) {
        $response->assertSee('DOC101')
            ->assertSee('Kardiologi')
            ->assertSee('STR-2026-101')
            ->assertSee('Terdaftar')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    if ($role === User::ROLE_PASIEN) {
        $response->assertSee('PAT101')
            ->assertSee('Belum Terdaftar')
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false);
    }
})->with([
    'admin' => [User::ROLE_ADMIN, 'Panel Administrator', 'Admin', 'Administrator', 'admin.dashboard'],
    'dokter' => [User::ROLE_DOKTER, 'Panel Dokter', 'dr. Sari', 'Dokter', 'dokter.dashboard'],
    'pasien' => [User::ROLE_PASIEN, 'Panel Pasien', 'Budi', 'Pasien', 'pasien.dashboard'],
]);

it('displays the email row in the credentials card only when email is populated', function () {
    $userWithEmail = User::factory()->admin()->create([
        'username' => 'admin_email',
        'email' => 'admin.klinik@example.com',
    ]);

    $responseWithEmail = $this->actingAs($userWithEmail)->get(route('account.edit'));

    $responseWithEmail->assertOk();
    $matchedWithEmail = preg_match(
        '/<div class="panel-card" id="accountCredentialsCard">.*?<div class="panel-card">/s',
        $responseWithEmail->getContent(),
        $withEmailCard,
    );
    expect($matchedWithEmail)->toBe(1)
        ->and($withEmailCard[0])->toContain('Email')
        ->toContain('admin.klinik@example.com');

    $userWithoutEmail = User::factory()->admin()->create([
        'username' => 'admin_tanpa_email',
        'email' => null,
    ]);

    $responseWithoutEmail = $this->actingAs($userWithoutEmail)->get(route('account.edit'));

    $responseWithoutEmail->assertOk();
    $matchedWithoutEmail = preg_match(
        '/<div class="panel-card" id="accountCredentialsCard">.*?<div class="panel-card">/s',
        $responseWithoutEmail->getContent(),
        $withoutEmailCard,
    );
    expect($matchedWithoutEmail)->toBe(1)
        ->and($withoutEmailCard[0])->not->toContain('Email');
});

it('updates username and optional email in lowercase, allows clearing email to null, and authenticates with the new username', function () {
    $user = User::factory()->pasien()->create([
        'username' => 'pasien_lama',
        'email' => null,
        'password' => 'PasswordLama123!',
    ]);
    Patient::create([
        'patient_code' => 'PAT010',
        'user_id' => $user->id,
        'full_name' => 'Pasien Uji',
        'date_of_birth' => '1995-03-20',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Melati No. 5',
    ]);

    $updateResponse = $this->actingAs($user)
        ->from(route('account.edit'))
        ->put(route('account.credentials.update'), [
            'username' => '  pasien_baru.01  ',
            'email' => '  Pasien.Baru@Klinik.ID  ',
        ]);

    $updateResponse->assertRedirect(route('account.edit'))
        ->assertSessionHas('success', 'Kredensial akun berhasil diperbarui.');

    $user->refresh();
    expect($user->username)->toBe('pasien_baru.01')
        ->and($user->email)->toBe('pasien.baru@klinik.id');

    $this->actingAs($user)
        ->get(route('account.edit'))
        ->assertOk()
        ->assertSee('Kredensial akun berhasil diperbarui.')
        ->assertSee('pasien_baru.01')
        ->assertSee('pasien.baru@klinik.id');

    User::factory()->admin()->create([
        'username' => 'admin_email_null_lain',
        'email' => null,
    ]);

    $clearEmailResponse = $this->actingAs($user)
        ->from(route('account.edit'))
        ->put(route('account.credentials.update'), [
            'username' => 'pasien_baru.01',
            'email' => '   ',
        ]);

    $clearEmailResponse->assertRedirect(route('account.edit'))
        ->assertSessionHas('success', 'Kredensial akun berhasil diperbarui.');

    $user->refresh();
    expect($user->email)->toBeNull();

    $this->actingAs($user)
        ->get(route('account.edit'))
        ->assertOk()
        ->assertDontSee('pasien.baru@klinik.id');

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post(route('login.store'), [
        'username' => 'pasien_baru.01',
        'password' => 'PasswordLama123!',
    ])->assertRedirect(route('pasien.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('allows keeping the same username and email owned by the authenticated user', function () {
    $user = User::factory()->admin()->create([
        'username' => 'admin_tetap',
        'email' => 'admin.tetap@klinik.id',
    ]);

    $response = $this->actingAs($user)
        ->from(route('account.edit'))
        ->put(route('account.credentials.update'), [
            'username' => 'admin_tetap',
            'email' => 'ADMIN.TETAP@KLINIK.ID',
        ]);

    $response->assertRedirect(route('account.edit'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Kredensial akun berhasil diperbarui.');

    $user->refresh();
    expect($user->username)->toBe('admin_tetap')
        ->and($user->email)->toBe('admin.tetap@klinik.id');
});

it('rejects duplicate or invalid username and email values when updating credentials', function (array $payload, string $errorField, string $expectedMessage) {
    User::factory()->admin()->create([
        'username' => 'user_lain',
        'email' => 'dipakai@klinik.id',
    ]);

    $user = User::factory()->dokter()->create([
        'username' => 'dokter_asli',
        'email' => 'dokter.asli@klinik.id',
    ]);

    $response = $this->actingAs($user)
        ->from(route('account.edit'))
        ->put(route('account.credentials.update'), $payload);

    $response->assertRedirect(route('account.edit'))
        ->assertSessionHasErrors([$errorField => $expectedMessage]);

    $user->refresh();
    expect($user->username)->toBe('dokter_asli')
        ->and($user->email)->toBe('dokter.asli@klinik.id');
})->with([
    'duplicate username' => [
        ['username' => 'user_lain', 'email' => 'dokter.asli@klinik.id'],
        'username',
        'Username sudah digunakan oleh akun lain.',
    ],
    'duplicate email case insensitive' => [
        ['username' => 'dokter_asli', 'email' => 'DIPAKAI@KLINIK.ID'],
        'email',
        'Email sudah digunakan oleh akun lain.',
    ],
    'invalid email format' => [
        ['username' => 'dokter_asli', 'email' => 'bukan-email'],
        'email',
        'Format email tidak valid.',
    ],
    'username too short' => [
        ['username' => 'ab', 'email' => ''],
        'username',
        'Username minimal terdiri dari 3 karakter.',
    ],
    'empty username' => [
        ['username' => '', 'email' => ''],
        'username',
        'Username wajib diisi.',
    ],
    'invalid username characters' => [
        ['username' => 'dokter spasi', 'email' => ''],
        'username',
        'Username hanya boleh berisi huruf, angka, titik (.), garis bawah (_), atau tanda hubung (-).',
    ],
]);

it('updates the password with only two inputs without requiring current password and enforces new password on login', function () {
    $user = User::factory()->admin()->create([
        'username' => 'admin_sandi',
        'password' => 'SandiLama123!',
    ]);

    $response = $this->actingAs($user)
        ->from(route('account.edit'))
        ->put(route('account.password.update'), [
            'password' => 'SandiBaru456!',
            'password_confirmation' => 'SandiBaru456!',
        ]);

    $response->assertRedirect(route('account.edit'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Password berhasil diperbarui.');

    $user->refresh();
    expect(Hash::check('SandiBaru456!', $user->password))->toBeTrue()
        ->and(Hash::check('SandiLama123!', $user->password))->toBeFalse();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

    $this->from(route('login'))->post(route('login.store'), [
        'username' => 'admin_sandi',
        'password' => 'SandiLama123!',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    $this->post(route('login.store'), [
        'username' => 'admin_sandi',
        'password' => 'SandiBaru456!',
    ])->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('rejects password updates when confirmation mismatches or password is shorter than 8 characters', function (array $payload, string $errorField, string $expectedMessage) {
    $user = User::factory()->pasien()->create([
        'username' => 'pasien_sandi',
        'password' => 'PasswordTetap123!',
    ]);

    $response = $this->actingAs($user)
        ->from(route('account.edit'))
        ->put(route('account.password.update'), $payload);

    $response->assertRedirect(route('account.edit'))
        ->assertSessionHasErrors([$errorField => $expectedMessage]);

    $user->refresh();
    expect(Hash::check('PasswordTetap123!', $user->password))->toBeTrue();
})->with([
    'password shorter than 8 chars' => [
        ['password' => 'abc1234', 'password_confirmation' => 'abc1234'],
        'password',
        'Password baru minimal terdiri dari 8 karakter.',
    ],
    'empty password' => [
        ['password' => '', 'password_confirmation' => ''],
        'password',
        'Password baru wajib diisi.',
    ],
    'password confirmation mismatch' => [
        ['password' => 'PasswordBaru123!', 'password_confirmation' => 'TidakCocok123!'],
        'password',
        'Konfirmasi password baru tidak cocok dengan password baru.',
    ],
    'missing password confirmation' => [
        ['password' => 'PasswordBaru123!', 'password_confirmation' => ''],
        'password_confirmation',
        'Konfirmasi password baru wajib diisi.',
    ],
]);

it('does not alter role or wallet_address when injected into account update requests', function () {
    $originalWallet = '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890';
    $injectedWallet = '0x9999999999999999999999999999999999999999';

    $user = User::factory()->pasien()->create([
        'username' => 'pasien_terlindungi',
        'email' => 'awal@klinik.id',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => $originalWallet,
    ]);

    $this->actingAs($user)
        ->put(route('account.credentials.update'), [
            'username' => 'pasien_diubah',
            'email' => 'baru@klinik.id',
            'role' => User::ROLE_ADMIN,
            'wallet_address' => $injectedWallet,
        ])
        ->assertRedirect(route('account.edit'))
        ->assertSessionHas('success', 'Kredensial akun berhasil diperbarui.');

    $this->actingAs($user)
        ->put(route('account.password.update'), [
            'password' => 'PasswordAman123!',
            'password_confirmation' => 'PasswordAman123!',
            'role' => User::ROLE_ADMIN,
            'wallet_address' => $injectedWallet,
        ])
        ->assertRedirect(route('account.edit'))
        ->assertSessionHas('success', 'Password berhasil diperbarui.');

    $user->refresh();
    expect($user->username)->toBe('pasien_diubah')
        ->and($user->email)->toBe('baru@klinik.id')
        ->and($user->role)->toBe(User::ROLE_PASIEN)
        ->and($user->wallet_address)->toBe($originalWallet);
});
