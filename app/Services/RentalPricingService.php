<?php

namespace App\Services;

use App\Support\Rental\RentalQuote;
use InvalidArgumentException;

/**
 * Computes the cost of a rental.
 *
 * Ported from the `Grok-show` rental prototype (`src/lib/rental/pricing.ts`),
 * where the quote was calculated in the browser purely to drive a live price
 * preview. Here it is server-side and authoritative: per CLAUDE.md, "the
 * backend is the trusted source for price and totals. Never trust a
 * frontend-submitted price, discount or total." A page may mirror this
 * arithmetic in JS for instant feedback, but the charged amount must come
 * from here.
 *
 * The duration discount tiers the prototype hardcoded (5/10/15% at
 * 7/14/30 days) are exposed through `config('rental.pricing.duration_discounts')`
 * so they can be tuned without a code change. They are carried over as-is
 * because they came from the prototype, not because they are a confirmed
 * commercial policy — the owner has not signed off on rental pricing rules.
 */
class RentalPricingService
{
    /**
     * Discount fraction applied to the subtotal for a rental of $days.
     *
     * Tiers are matched longest-first, so the most generous qualifying tier
     * wins regardless of the order they are declared in config.
     */
    public function discountRateForDays(int $days): float
    {
        $tiers = config('rental.pricing.duration_discounts', []);

        krsort($tiers, SORT_NUMERIC);

        foreach ($tiers as $minDays => $rate) {
            if ($days >= (int) $minDays) {
                return (float) $rate;
            }
        }

        return 0.0;
    }

    /**
     * Build a full cost breakdown.
     *
     * All money arguments are whole Toman. `$deposit` is returned on the
     * quote but never added to `payableNow` — see RentalQuote.
     *
     * @param  int  $dailyRate  per-day rental price of the item
     * @param  int  $days  rental length in days, inclusive of the start day
     * @param  int  $extraControllerDaily  per-day price of a second controller, 0 if unavailable
     * @param  bool  $withExtraController  whether the customer added that second controller
     * @param  int  $gameFee  one-off fee for a paid game title, 0 when bundled free
     * @param  int  $deliveryFee  courier fee, 0 for in-person pickup
     * @param  int  $deposit  security deposit -- how it is held, released or
     *                        refunded is undecided (B4); nothing here charges it
     */
    public function quote(
        int $dailyRate,
        int $days,
        int $extraControllerDaily = 0,
        bool $withExtraController = false,
        int $gameFee = 0,
        int $deliveryFee = 0,
        int $deposit = 0,
    ): RentalQuote {
        if ($days < 1) {
            throw new InvalidArgumentException('Rental length must be at least one day.');
        }

        foreach (compact('dailyRate', 'extraControllerDaily', 'gameFee', 'deliveryFee', 'deposit') as $name => $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException("Rental amount `{$name}` cannot be negative.");
            }
        }

        $extraControllerFee = $withExtraController ? $extraControllerDaily * $days : 0;

        $subtotal = ($dailyRate * $days) + $extraControllerFee + $gameFee;

        $discountRate = $this->discountRateForDays($days);
        $discount = (int) round($subtotal * $discountRate);

        $rentalTotal = $subtotal - $discount;

        return new RentalQuote(
            days: $days,
            dailyRate: $dailyRate,
            extraControllerFee: $extraControllerFee,
            gameFee: $gameFee,
            subtotal: $subtotal,
            discountRate: $discountRate,
            discount: $discount,
            deliveryFee: $deliveryFee,
            rentalTotal: $rentalTotal,
            deposit: $deposit,
            payableNow: $rentalTotal + $deliveryFee,
        );
    }
}
