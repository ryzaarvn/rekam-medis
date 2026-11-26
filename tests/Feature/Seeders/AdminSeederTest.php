<?php

use App\Models\Authorization;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds a single idempotent admin user from blockchain config without fake transaction data', function () {
    Config::set('blockchain.admin_wallet_address', '0x42B01A713EA7A8ECA09D36A9D8FA177AC301E1C3');

    $this->seed(DatabaseSeeder::class);
    $this->seed(AdminSeeder::class);

    $admin = User::query()->where('username', 'admin')->first();

    expect(User::query()->count())->toBe(1)
        ->and($admin)->not->toBeNull()
        ->and($admin->role)->toBe(User::ROLE_ADMIN)
        ->and($admin->wallet_address)->toBe('0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3')
        ->and(Hash::check('Admin12345!', $admin->password))->toBeTrue();

    expect(Authorization::query()->count())->toBe(0);
    expect(MedicalRecord::query()->count())->toBe(0);
    expect(LogBlockchain::query()->count())->toBe(0);
});

it('throws an exception when admin_wallet_address configuration is missing or invalid', function (string $invalidAddress) {
    Config::set('blockchain.admin_wallet_address', $invalidAddress);

    expect(fn () => $this->seed(AdminSeeder::class))
        ->toThrow(RuntimeException::class, 'ADMIN_WALLET_ADDRESS');
})->with([
    'empty' => [''],
    'short hex' => ['0x1234'],
]);
