<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * The calculated 35/65 split of one owner rental. NOT a payment.
 *
 * Written by RentalSettlementService alone; immutable once saved. Nothing is
 * mass-assignable. `status` is always `calculated` -- see the migration for
 * why no paid/settled value exists.
 */
class RentalSettlement extends Model
{
    public const STATUS_CALCULATED = 'calculated';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer',
            'commission_bps' => 'integer',
            'gamepek_share' => 'integer',
            'owner_share' => 'integer',
            'calculated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function generateReference(): string
    {
        return 'STL-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Rental settlements are immutable once calculated.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Rental settlements cannot be deleted.');
    }

    /** Present only once the owner's share actually reached their wallet. */
    public function credit(): HasOne
    {
        return $this->hasOne(RentalSettlementCredit::class, 'rental_settlement_id');
    }

    public function isCredited(): bool
    {
        return $this->relationLoaded('credit') ? $this->credit !== null : $this->credit()->exists();
    }

    public function statusLabel(): string
    {
        // Calculated is never presented as paid.
        return $this->isCredited() ? 'به کیف پول مالک واریز شد' : 'محاسبه‌شده — واریز نشده';
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(RentalReservation::class, 'rental_reservation_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
