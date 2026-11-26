<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'doctor_code',
        'user_id',
        'full_name',
        'specialization',
        'str_number',
        'is_registered_onchain',
        'registration_tx_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_registered_onchain' => 'boolean',
        ];
    }

    /**
     * Normalize registration transaction hash to lowercase to satisfy the PostgreSQL check constraint.
     */
    protected function registrationTxHash(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : strtolower(trim($value)),
        );
    }

    /**
     * Scope the query to doctors registered on-chain.
     */
    #[Scope]
    protected function registeredOnchain(Builder $query): Builder
    {
        return $query->where('is_registered_onchain', true);
    }

    /**
     * Get the user account that owns the doctor profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the medical records created or managed by the doctor.
     */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class);
    }

    /**
     * Get the patient authorization entries for the doctor.
     */
    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class);
    }

    /**
     * Get the patients linked to the doctor through authorization records.
     */
    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'authorizations')
            ->withPivot(['is_authorized', 'tx_hash'])
            ->withTimestamps();
    }

    /**
     * Get the patients who currently authorize this doctor.
     */
    public function authorizedPatients(): BelongsToMany
    {
        return $this->patients()->wherePivot('is_authorized', true);
    }

    /**
     * Get the blockchain transaction logs submitted by the doctor.
     */
    public function logBlockchains(): HasMany
    {
        return $this->hasMany(LogBlockchain::class);
    }
}
