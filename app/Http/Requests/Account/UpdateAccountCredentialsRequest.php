<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountCredentialsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalize username and email inputs before validation.
     */
    protected function prepareForValidation(): void
    {
        $rawUsername = $this->input('username', '');
        $rawEmail = $this->input('email', '');
        $email = is_string($rawEmail) ? trim($rawEmail) : $rawEmail;

        $this->merge([
            'username' => is_string($rawUsername) ? trim($rawUsername) : $rawUsername,
            'email' => $email === '' ? null : (is_string($email) ? strtolower($email) : $email),
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
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'regex:/\A[a-zA-Z0-9_.-]+\z/',
                Rule::unique('users', 'username')->ignore($this->user()->id),
            ],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:100',
                Rule::unique('users', 'email')->ignore($this->user()->id),
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
            'username.required' => 'Username wajib diisi.',
            'username.string' => 'Username harus berupa teks.',
            'username.min' => 'Username minimal terdiri dari 3 karakter.',
            'username.max' => 'Username maksimal terdiri dari 50 karakter.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik (.), garis bawah (_), atau tanda hubung (-).',
            'username.unique' => 'Username sudah digunakan oleh akun lain.',
            'email.string' => 'Email harus berupa teks.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal terdiri dari 100 karakter.',
            'email.unique' => 'Email sudah digunakan oleh akun lain.',
        ];
    }
}
