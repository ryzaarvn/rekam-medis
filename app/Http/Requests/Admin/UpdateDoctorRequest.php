<?php

namespace App\Http\Requests\Admin;

use App\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDoctorRequest extends FormRequest
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
        $email = trim((string) $this->input('email', ''));
        $password = (string) $this->input('password', '');

        $this->merge([
            'doctor_code' => strtoupper(trim((string) $this->input('doctor_code', ''))),
            'full_name' => trim((string) $this->input('full_name', '')),
            'specialization' => trim((string) $this->input('specialization', '')),
            'str_number' => trim((string) $this->input('str_number', '')),
            'username' => trim((string) $this->input('username', '')),
            'email' => $email === '' ? null : strtolower($email),
            'password' => trim($password) === '' ? null : $password,
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
        /** @var Doctor $doctor */
        $doctor = $this->route('doctor');

        return [
            'doctor_code' => ['required', 'string', 'max:20', 'regex:/\A[\x21-\x7E]+\z/', Rule::unique('doctors', 'doctor_code')->ignore($doctor->id)],
            'full_name' => ['required', 'string', 'max:100'],
            'specialization' => ['required', 'string', 'max:100'],
            'str_number' => ['required', 'string', 'max:50', Rule::unique('doctors', 'str_number')->ignore($doctor->id)],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[a-zA-Z0-9_.-]+\z/', Rule::unique('users', 'username')->ignore($doctor->user_id)],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('users', 'email')->ignore($doctor->user_id)],
            'password' => ['nullable', 'string', 'min:8'],
            'wallet_address' => ['required', 'string', 'regex:/\A0x[a-f0-9]{40}\z/', Rule::unique('users', 'wallet_address')->ignore($doctor->user_id)],
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
        ];
    }
}
