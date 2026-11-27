<?php

namespace App\Http\Requests\Admin;

use App\Models\Patient;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Normalize inputs before validation.
     */
    protected function prepareForValidation(): void
    {
        $patientCode = trim((string) $this->input('patient_code', ''));
        $email = trim((string) $this->input('email', ''));

        $this->merge([
            'patient_code' => $patientCode === '' ? null : strtoupper($patientCode),
            'full_name' => trim((string) $this->input('full_name', '')),
            'address' => trim((string) $this->input('address', '')),
            'username' => trim((string) $this->input('username', '')),
            'email' => $email === '' ? null : strtolower($email),
            'wallet_address' => strtolower(trim((string) $this->input('wallet_address', ''))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $adminWallet = strtolower(trim((string) config('blockchain.admin_wallet_address', '')));

        return [
            'patient_code' => ['nullable', 'string', 'max:20', 'regex:/\A[\x21-\x7E]+\z/', Rule::unique('patients', 'patient_code')],
            'full_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'gender' => ['required', 'string', Rule::in(Patient::GENDERS)],
            'address' => ['required', 'string', 'max:1000'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[a-zA-Z0-9_.-]+\z/', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'wallet_address' => [
                'required',
                'string',
                'regex:/\A0x[a-f0-9]{40}\z/',
                Rule::unique('users', 'wallet_address'),
                function (string $attribute, mixed $value, Closure $fail) use ($adminWallet): void {
                    if ($adminWallet !== '' && is_string($value) && strtolower($value) === $adminWallet) {
                        $fail('Alamat wallet Admin smart contract tidak dapat didaftarkan sebagai pasien.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'wallet_address.regex' => 'Format alamat wallet Ethereum Sepolia tidak valid (harus 0x diikuti 40 karakter hex).',
            'wallet_address.unique' => 'Alamat wallet Ethereum sudah digunakan oleh akun lain.',
            'gender.in' => 'Jenis kelamin harus Laki-laki atau Perempuan.',
        ];
    }
}
