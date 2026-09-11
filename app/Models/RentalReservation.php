<?php

namespace App\Models;

use App\Enums\ReservationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
            'returned_on' => 'date',
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
     * NULL when a reservation is paid: which free device it gets is still a
     * human choice (no selection policy is decided). It is filled only when
     * staff attach one through an operational task, and attachDevice() refuses
     * a choice that would leave another paid reservation of the same product
     * without any possible device (RentalAvailabilityService).
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** The calculated 35/65 split, once one exists. Never a payment. */
    public function settlement(): HasOne
    {
        return $this->hasOne(RentalSettlement::class, 'rental_reservation_id');
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
        // The blocking period ends at the ACTUAL return when the customer
        // brought the device back early, otherwise at the contractual end.
        // Still the one overlap predicate in the codebase.
        return $query->where('product_id', $productId)
            ->where('start_date', '<=', $endDate)
            ->whereRaw('LEAST(end_date, COALESCE(returned_on, end_date)) >= ?', [$startDate]);
    }

    /**
     * The last day this reservation occupies its device: the actual return
     * day after an early return, else the contractual end date.
     */
    public function blockedUntil(): Carbon
    {
        return $this->returned_on !== null && $this->returned_on->lessThan($this->end_date)
            ? $this->returned_on
            : $this->end_date;
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
