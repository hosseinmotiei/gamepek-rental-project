<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One live signing challenge per (contract, signer).
 *
 * `code_hash` is a keyed HMAC, never the digits: a database-only compromise
 * cannot brute-force the code back out of a 5-digit keyspace without APP_KEY.
 */
class ContractSignatureOtp extends Model
{
    use MassPrunable;

    protected $fillable = [
        'contract_id', 'user_id', 'code_hash', 'expires_at',
        'attempts', 'consumed_at', 'requested_ip', 'correlation_id',
    ];

    /** Never serialise the hash. */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
