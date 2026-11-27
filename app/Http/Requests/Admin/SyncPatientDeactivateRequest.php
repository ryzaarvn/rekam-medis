<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncPatientDeactivateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
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
            'tx_hash.required' => 'Transaction hash Sepolia wajib disertakan untuk menonaktifkan pasien on-chain.',
            'tx_hash.regex' => 'Format transaction hash Sepolia tidak valid (harus 0x diikuti 64 karakter hex).',
        ];
    }
}
