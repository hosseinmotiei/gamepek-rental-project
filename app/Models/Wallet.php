<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One user's persisted wallet balance.
 *
 * `balance` is deliberately NOT fillable. WalletService is the only writer --
 * it always sets balance via a direct property assignment inside a locked
 * transaction that also writes the WalletTransaction explaining the change,
 * the same single-writer discipline RentalChainOrchestrator applies to
 * `rental_applications.state`. If you find `Wallet::update(['balance' => ...])`
 * or a mass-assigned balance anywhere, that is the bug.
 */
class Wallet extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
