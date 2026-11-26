<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogBlockchain extends Model
{
    /**
     * Only created_at exists on log_blockchains; disable updated_at handling.
     */
    public const UPDATED_AT = null;

    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    /**
     * Allowed blockchain action types matching the PostgreSQL check constraint.
     *
     * @var list<string>
     */
    public const ACTION_TYPES = [
        self::ACTION_CREATE,
        self::ACTION_UPDATE,
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'log_blockchains';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'medical_record_id',
        'doctor_id',
        'onchain_hash',
        'tx_hash',
        'block_number',
        'action_type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'block_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Normalize on-chain hash to lowercase to satisfy the PostgreSQL check constraint.
     */
    protected function onchainHash(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : strtolower(trim($value)),
        );
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
     * Get the medical record referenced by the blockchain log.
     */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    /**
     * Get the doctor who submitted the blockchain transaction.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
