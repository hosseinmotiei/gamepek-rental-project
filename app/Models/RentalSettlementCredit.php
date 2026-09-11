<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof a settlement's owner share reached the owner's wallet. Append-only;
 * written by RentalSettlementService::finalize() alone.
 */
class RentalSettlementCredit extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'credited_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Settlement credits are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Settlement credits are append-only.');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
