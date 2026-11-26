<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MedicalRecord extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'record_code',
        'patient_id',
        'doctor_id',
        'record_date',
        'complaint',
        'diagnosis',
        'treatment',
        'notes',
        'current_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'record_date' => 'date',
        ];
    }

    /**
     * Normalize canonical SHA-256 hash to lowercase to satisfy the PostgreSQL check constraint.
     */
    protected function currentHash(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : strtolower(trim($value)),
        );
    }

    /**
     * Get the patient associated with the medical record.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the doctor who authored the medical record.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get all blockchain audit logs for the medical record.
     */
    public function logBlockchains(): HasMany
    {
        return $this->hasMany(LogBlockchain::class);
    }

    /**
     * Get the most recent blockchain log for the medical record.
     */
    public function latestLogBlockchain(): HasOne
    {
        return $this->hasOne(LogBlockchain::class)->latestOfMany();
    }
}
