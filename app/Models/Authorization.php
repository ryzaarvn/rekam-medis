<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Authorization extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'doctor_id',
        'is_authorized',
        'tx_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_authorized' => 'boolean',
        ];
    }

    /**
     * Normalize transaction hash to lowercase to satisfy the PostgreSQL check constraint.
     */
    protected function txHash(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : strtolower(trim($value)),
        );
    }

    /**
     * Scope the query to active authorizations.
     */
    #[Scope]
    protected function authorized(Builder $query): Builder
    {
        return $query->where('is_authorized', true);
    }

    /**
     * Get the patient who granted or revoked the authorization.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the doctor referenced by the authorization.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
