<?php

namespace App\Http\Requests\Dokter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrepareStoreMedicalRecordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isDoctor() ?? false;
    }

    /**
     * Normalize text fields before validation.
     */
    protected function prepareForValidation(): void
    {
        $notes = trim((string) $this->input('notes', ''));

        $this->merge([
            'complaint' => trim((string) $this->input('complaint', '')),
            'diagnosis' => trim((string) $this->input('diagnosis', '')),
            'treatment' => trim((string) $this->input('treatment', '')),
            'notes' => $notes === '' ? null : $notes,
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
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')],
            'record_date' => ['required', 'date'],
            'complaint' => ['required', 'string', 'max:5000'],
            'diagnosis' => ['required', 'string', 'max:5000'],
            'treatment' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
