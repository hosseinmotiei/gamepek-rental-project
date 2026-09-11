<?php

namespace App\Models;

use App\Enums\RentalApplicationState;
use App\Enums\ReservationState;
use App\Support\Rental\LateReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One paid booking of a product for a date range.
 *
 * WHICH STATE IS AUTHORITATIVE. `rental_applications.state` is the rental's
 * lifecycle -- Approved, Active, Returned, Closed -- and
 * RentalChainOrchestrator is its only writer. `rental_reservations.state`
 * (ReservationState) is NOT a second lifecycle: it says only whether this
 * booking blocks inventory (see scopeBlocking), and in the current flow a
 * reservation is written once, as `paid`, and never advanced again. Nothing
 * may read it to decide where a rental stands.
 */
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
        $today = now()->toDateString();

        return $query->where('product_id', $productId)
            ->where('start_date', '<=', $endDate)
            ->whereRaw(self::BLOCKED_UNTIL_SQL.' >= ?', [$today, RentalApplicationState::Active->value, $startDate]);
    }

    /**
     * SQL twin of blockedUntil(), so the query and the PHP answer cannot drift.
     * Bindings, in order: today, the Active state value.
     */
    public const BLOCKED_UNTIL_SQL = '(CASE
        WHEN rental_reservations.returned_on IS NOT NULL THEN rental_reservations.returned_on
        WHEN rental_reservations.end_date < ? AND EXISTS (
            SELECT 1 FROM rental_applications ra
            WHERE ra.id = rental_reservations.rental_application_id AND ra.state = ?
        ) THEN \''.self::OPEN_ENDED.'\'
        ELSE rental_reservations.end_date
    END)';

    /**
     * The device is still physically out and no end date can be named yet.
     * A far-future date rather than null, so it compares like any other.
     */
    public const OPEN_ENDED = '9999-12-31';

    /**
     * The last day this reservation occupies its device.
     *
     *  - returned: the day GamePek ACTUALLY received it back, whether that was
     *    early (the remaining days are freed) or late (the extra days were
     *    genuinely occupied and stay blocked);
     *  - CONFIRMED late-return rule: past the contractual end with the device
     *    still in the customer's hands, the device is not released at all --
     *    it stays unavailable until the physical return is recorded, so this
     *    answers OPEN_ENDED;
     *  - otherwise the contractual end date.
     *
     * "Still in the customer's hands" is the rental being Active: a Returned or
     * Closed rental whose `returned_on` was never written (rows from before
     * that column existed) falls back to its contractual end and does NOT
     * block the device forever.
     */
    public function blockedUntil(): Carbon
    {
        if ($this->returned_on !== null) {
            return $this->returned_on;
        }

        if ($this->end_date->toDateString() < now()->toDateString() && $this->isStillWithCustomer()) {
            return Carbon::parse(self::OPEN_ENDED);
        }

        return $this->end_date;
    }

    /** Is this rental still running, i.e. the device has not come back? */
    public function isStillWithCustomer(): bool
    {
        // Deliberately a fresh query, not $this->application: the relation is
        // rarely loaded here and strict mode forbids a lazy load. It only runs
        // for a reservation already known to be past its end date.
        return RentalApplication::whereKey($this->rental_application_id)->value('state')
            === RentalApplicationState::Active;
    }

    /** The confirmed late-return position of this rental. Calculation only. */
    public function lateReturn(?string $today = null): LateReturn
    {
        return LateReturn::for($this, $today);
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
