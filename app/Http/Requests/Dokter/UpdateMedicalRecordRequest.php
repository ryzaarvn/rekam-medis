<?php

namespace App\Http\Requests\Dokter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicalRecordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isDoctor() ?? false;
    }

    /**
     * Normalize hashes and text fields before validation.
     */
    protected function prepareForValidation(): void
    {
        $notes = trim((string) $this->input('notes', ''));

        $this->merge([
            'complaint' => trim((string) $this->input('complaint', '')),
            'diagnosis' => trim((string) $this->input('diagnosis', '')),
            'treatment' => trim((string) $this->input('treatment', '')),
            'notes' => $notes === '' ? null : $notes,
            'current_hash' => strtolower(trim((string) $this->input('current_hash', ''))),
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
            'record_date' => ['required', 'date'],
            'complaint' => ['required', 'string', 'max:5000'],
            'diagnosis' => ['required', 'string', 'max:5000'],
            'treatment' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'current_hash' => ['required', 'string', 'regex:/\A0x[a-f0-9]{64}\z/'],
            'tx_hash' => ['required', 'string', 'regex:/\A0x[a-f0-9]{64}\z/', Rule::unique('log_blockchains', 'tx_hash')],
            'block_number' => ['nullable', 'integer', 'min:0'],
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
            'current_hash.regex' => 'Format canonical hash tidak valid (harus 0x diikuti 64 karakter hex).',
            'tx_hash.regex' => 'Format transaction hash Sepolia tidak valid (harus 0x diikuti 64 karakter hex).',
            'tx_hash.unique' => 'Transaction hash ini sudah tercatat di log blockchain.',
        ];
    }
}
