<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Patient extends Model
{
    public const GENDER_MALE = 'Laki-laki';

    public const GENDER_FEMALE = 'Perempuan';

    /**
     * Allowed gender values matching the PostgreSQL check constraint.
     *
     * @var list<string>
     */
    public const GENDERS = [
        self::GENDER_MALE,
        self::GENDER_FEMALE,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_code',
        'user_id',
        'full_name',
        'date_of_birth',
        'gender',
        'address',
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
            'date_of_birth' => 'date',
            'is_registered_onchain' => 'boolean',
        ];
    }

    /**
     * Normalize registration transaction hash to lowercase to satisfy the PostgreSQL check constraint.
     */
    protected function registrationTxHash(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }

                $normalized = strtolower(trim($value));

                return $normalized === '' ? null : $normalized;
            },
        );
    }

    /**
     * Scope the query to patients registered on-chain.
     */
    #[Scope]
    protected function registeredOnchain(Builder $query): Builder
    {
        return $query->where('is_registered_onchain', true);
    }

    /**
     * Get the user account that owns the patient profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the medical records belonging to the patient.
     */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class);
    }

    /**
     * Get the doctor authorization entries for the patient.
     */
    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class);
    }

    /**
     * Get the doctors linked to the patient through authorization records.
     */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'authorizations')
            ->withPivot(['is_authorized', 'tx_hash'])
            ->withTimestamps();
    }

    /**
     * Get the doctors currently authorized by the patient.
     */
    public function authorizedDoctors(): BelongsToMany
    {
        return $this->doctors()->wherePivot('is_authorized', true);
    }

    /**
     * Get the blockchain audit logs associated with the patient's medical records.
     */
    public function logBlockchains(): HasManyThrough
    {
        return $this->hasManyThrough(LogBlockchain::class, MedicalRecord::class);
    }
}
