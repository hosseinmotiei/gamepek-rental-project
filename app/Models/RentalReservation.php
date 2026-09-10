<?php

namespace App\Models;

use App\Enums\ReservationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalReservation extends Model
{
    protected $fillable = [
        'rental_application_id', 'product_id', 'product_snapshot',
        'start_date', 'end_date', 'days',
        'daily_rate', 'subtotal', 'discount', 'delivery_fee',
        'rental_total', 'deposit_amount', 'payable_now', 'quote',
        'state', 'held_until',
    ];

    protected function casts(): array
    {
        return [
            'product_snapshot' => 'array',
            'quote' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'held_until' => 'datetime',
            'days' => 'integer',
            'daily_rate' => 'integer',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'delivery_fee' => 'integer',
            'rental_total' => 'integer',
            'deposit_amount' => 'integer',
            'payable_now' => 'integer',
            'state' => ReservationState::class,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class, 'rental_application_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The physical device serving this reservation.
     *
     * NULL on every row written today: which free device a paid reservation
     * gets is an undecided policy (see the device_id migration). It is filled
     * only when a human attaches one explicitly through an operational task.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RentalOperation::class, 'rental_reservation_id');
    }

    /**
     * Half-open overlap test: two reservations conflict when one starts before
     * the other ends and vice versa. Kept as a scope so every caller asks the
     * same question -- there must not be a second interval-overlap concept.
     */
    public function scopeOverlapping($query, int $productId, string $startDate, string $endDate)
    {
        return $query->where('product_id', $productId)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);
    }

    /**
     * The reservation states that actually block inventory.
     *
     * Confirmed business rule C-16: there is no unpaid reservation hold.
     * `held` and `awaiting_payment` are therefore NOT blocking states -- an
     * unpaid application must never take a device off the market.
     *
     * Under the current flow no reservation is created before payment at all
     * (see RentalReservationService), so `held` and `awaiting_payment` can only
     * appear on legacy rows written before that rule was implemented. Those
     * rows are deliberately left in place, and by being excluded here they stop
     * blocking without any data being rewritten.
     *
     * The enum cases are retained for backward compatibility with those rows
     * and with the transition ledger; they are simply never produced any more.
     */
    public function scopeBlocking($query)
    {
        return $query->whereIn('state', [
            ReservationState::Paid->value,
            ReservationState::Active->value,
        ]);
    }
}
