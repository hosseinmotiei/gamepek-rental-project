<?php

namespace App\Models;

use App\Enums\ReservationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function scopeBlocking($query)
    {
        return $query->whereIn('state', [
            ReservationState::Held->value,
            ReservationState::AwaitingPayment->value,
            ReservationState::Paid->value,
            ReservationState::Active->value,
        ]);
    }
}
