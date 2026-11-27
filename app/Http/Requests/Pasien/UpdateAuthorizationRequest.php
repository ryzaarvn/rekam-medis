<?php

namespace App\Http\Requests\Pasien;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuthorizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isPatient() ?? false;
    }

    /**
     * Normalize transaction hash to lowercase before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'tx_hash' => strtolower(trim((string) $this->input('tx_hash', ''))),
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
            'tx_hash' => [
                'required',
                'string',
                'regex:/\A0x[a-f0-9]{64}\z/',
                Rule::unique('authorizations', 'tx_hash'),
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
            'tx_hash.regex' => 'Format transaction hash Sepolia tidak valid (harus 0x diikuti 64 karakter hex).',
            'tx_hash.unique' => 'Transaction hash ini sudah tercatat pada riwayat otorisasi lain.',
        ];
    }
}
