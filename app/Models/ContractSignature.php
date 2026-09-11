<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signature EVIDENCE. Immutable once written.
 *
 * What was signed (signed_content_hash), the seal (signature), who, when, from
 * where and with which OTP -- none of it may change after the fact, or it stops
 * being evidence. The one column the flow legitimately fills later is
 * `verified_at` (ContractService::verifySignature()); every other change, and
 * any delete, throws. Same pattern as the wallet ledger and the note events.
 */
class ContractSignature extends Model
{
    /** The only column that may change on a persisted signature. */
    private const MUTABLE_AFTER_CREATE = ['verified_at'];

    protected static function booted(): void
    {
        static::updating(function (self $signature) {
            $changed = array_diff(array_keys($signature->getDirty()), self::MUTABLE_AFTER_CREATE, ['updated_at']);

            if ($changed !== []) {
                throw new \LogicException('Contract signature evidence is immutable: '.implode(', ', $changed));
            }
        });

        static::deleting(function () {
            throw new \LogicException('Contract signature evidence cannot be deleted.');
        });
    }

    protected $fillable = [
        'contract_id', 'user_id', 'method',
        'signed_content_hash', 'signature', 'otp_reference',
        'ip_address', 'user_agent', 'evidence', 'signed_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'signed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
