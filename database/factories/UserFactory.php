<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_PASIEN,
            'wallet_address' => '0x'.strtolower(bin2hex(random_bytes(20))),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Configure the user as an administrator.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => User::ROLE_ADMIN,
        ]);
    }

    /**
     * Configure the user as a doctor.
     */
    public function dokter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => User::ROLE_DOKTER,
        ]);
    }

    /**
     * Configure the user as a patient.
     */
    public function pasien(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => User::ROLE_PASIEN,
        ]);
    }
}
