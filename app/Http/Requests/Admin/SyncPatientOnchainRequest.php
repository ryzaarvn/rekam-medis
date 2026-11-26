<?php

namespace App\Http\Requests\Admin;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncPatientOnchainRequest extends FormRequest
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
        $rawTxHash = $this->input('registration_tx_hash', $this->input('tx_hash', ''));

        $this->merge([
            'registration_tx_hash' => strtolower(trim((string) $rawTxHash)),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Patient $patient */
        $patient = $this->route('patient');

        return [
            'registration_tx_hash' => [
                'required',
                'string',
                'regex:/\A0x[a-f0-9]{64}\z/',
                Rule::unique('patients', 'registration_tx_hash')->ignore($patient->id),
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
            'registration_tx_hash.regex' => 'Format transaction hash Sepolia tidak valid (harus 0x diikuti 64 karakter hex).',
            'registration_tx_hash.unique' => 'Transaction hash registrasi ini sudah tercatat pada pasien lain.',
        ];
    }
}
