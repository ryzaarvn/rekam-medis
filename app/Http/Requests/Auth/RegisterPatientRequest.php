<?php

namespace App\Http\Requests\Auth;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPatientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize request inputs to match PostgreSQL constraints.
     */
    protected function prepareForValidation(): void
    {
        $email = trim((string) $this->input('email', ''));

        $this->merge([
            'username' => trim((string) $this->input('username', '')),
            'email' => $email === '' ? null : strtolower($email),
            'full_name' => trim((string) $this->input('full_name', '')),
            'wallet_address' => strtolower(trim((string) $this->input('wallet_address', ''))),
            'address' => trim((string) $this->input('address', '')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['nullable', Rule::in([User::ROLE_PASIEN])],
            'full_name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[a-zA-Z0-9_.-]+\z/', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'wallet_address' => ['required', 'string', 'regex:/\A0x[a-f0-9]{40}\z/', Rule::unique('users', 'wallet_address')],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'gender' => ['required', 'string', Rule::in(Patient::GENDERS)],
            'address' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom validation error messages in Indonesian.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Pendaftaran publik hanya diperuntukkan bagi pasien.',
            'wallet_address.regex' => 'Format alamat wallet Ethereum Sepolia tidak valid (harus 0x diikuti 40 karakter hex).',
            'wallet_address.unique' => 'Alamat wallet Ethereum sudah terdaftar pada akun lain.',
            'gender.in' => 'Jenis kelamin harus Laki-laki atau Perempuan.',
        ];
    }
}
