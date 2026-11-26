<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /**
     * Seed the default administrator account idempotently from blockchain config.
     */
    public function run(): void
    {
        $walletAddress = strtolower(trim((string) config('blockchain.admin_wallet_address', '')));

        if (preg_match('/\A0x[a-f0-9]{40}\z/', $walletAddress) !== 1) {
            throw new RuntimeException(
                'ADMIN_WALLET_ADDRESS belum dikonfigurasi dengan format Ethereum address yang valid (0x + 40 karakter hex).',
            );
        }

        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'email' => 'admin@erekammedis.local',
                'password' => 'Admin12345!',
                'role' => User::ROLE_ADMIN,
                'wallet_address' => $walletAddress,
            ],
        );
    }
}
