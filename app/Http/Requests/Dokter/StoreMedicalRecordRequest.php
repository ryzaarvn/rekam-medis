<?php

namespace App\Http\Requests\Dokter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicalRecordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isDoctor() ?? false;
    }

    /**
     * Normalize record code, hashes, and text fields before validation.
     */
    protected function prepareForValidation(): void
    {
        $notes = trim((string) $this->input('notes', ''));

        $this->merge([
            'record_code' => trim((string) $this->input('record_code', '')),
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
            'record_code' => ['required', 'string', 'max:20', 'regex:/\A[\x21-\x7E]{1,20}\z/', Rule::unique('medical_records', 'record_code')],
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')],
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
