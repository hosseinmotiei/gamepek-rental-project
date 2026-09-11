<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One immutable ledger entry. Append-only, exactly like `AuditEvent` and
 * `RentalApplicationTransition`: no `updated_at`, and WalletService (the only
 * writer) never issues an UPDATE or DELETE against this table.
 *
 * update()/delete() are overridden below to throw rather than silently
 * no-op or succeed -- a financial ledger's immutability should not rest on
 * "nothing in the app currently calls this" alone. A future controller,
 * console command or import is one mistake away from editing history; this
 * makes that mistake a thrown exception instead of a quietly rewritten
 * balance_after.
 */
class WalletTransaction extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public $timestamps = false;

    protected $fillable = [
        'wallet_id', 'reference_number', 'type', 'amount', 'balance_after',
        'reason', 'idempotency_key', 'correlation_id', 'context',
        'actor_type', 'actor_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** WAL-YYMMDD-XXXXXX. Quotable on the phone, unique in the database. */
    public static function generateReference(): string
    {
        return 'WAL-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }

    /**
     * The actual immutability guard. Overriding update() alone would not be
     * enough -- Eloquent's save() on an existing model does not call
     * update(), so `$entry->reason = 'x'; $entry->save();` would otherwise
     * still write. Guarding here catches every path; creation (when the
     * model does not yet exist) is unaffected.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Wallet ledger entries are immutable and can never be updated.');
        }

        return parent::save($options);
    }

    /** @return never */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Wallet ledger entries are immutable and can never be updated.');
    }

    /** @return never */
    public function delete(): ?bool
    {
        throw new \LogicException('Wallet ledger entries are immutable and can never be deleted.');
    }
}
