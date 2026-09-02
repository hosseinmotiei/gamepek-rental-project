<?php

namespace App\Support\Rental;

/**
 * The computed cost breakdown of a rental, as returned by RentalPricing.
 *
 * Every figure is a whole Toman amount. Immutable by construction: a quote
 * is a calculation result, not a mutable basket.
 *
 * `deposit` is deliberately excluded from `payableNow`. The deposit is a
 * refundable security hold released after the item comes back intact, not
 * part of the rental charge — conflating the two is exactly the mistake
 * that would over-charge a customer at checkout.
 */
class RentalQuote
{
    public function __construct(
        public readonly int $days,
        public readonly int $dailyRate,
        public readonly int $extraControllerFee,
        public readonly int $gameFee,
        public readonly int $subtotal,
        public readonly float $discountRate,
        public readonly int $discount,
        public readonly int $deliveryFee,
        public readonly int $rentalTotal,
        public readonly int $deposit,
        public readonly int $payableNow,
    ) {
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
            'daily_rate' => $this->dailyRate,
            'extra_controller_fee' => $this->extraControllerFee,
            'game_fee' => $this->gameFee,
            'subtotal' => $this->subtotal,
            'discount_rate' => $this->discountRate,
            'discount' => $this->discount,
            'delivery_fee' => $this->deliveryFee,
            'rental_total' => $this->rentalTotal,
            'deposit' => $this->deposit,
            'payable_now' => $this->payableNow,
        ];
    }
}
