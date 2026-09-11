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

    /** The system wallet that receives GamePek's own money (e.g. paid damage). */
    public const PURPOSE_GAMEPEK = 'gamepek';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isGamePek(): bool
    {
        return $this->purpose === self::PURPOSE_GAMEPEK;
    }

    /** Persian holder name for screens: the user, or GamePek itself. */
    public function holderLabel(): string
    {
        return $this->isGamePek() ? 'کیف پول گیم‌پک' : ($this->user?->full_name ?: '—');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
