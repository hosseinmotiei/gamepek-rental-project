<?php

namespace App\Support\Rental;

use App\Models\RentalReservation;
use Illuminate\Support\Carbon;

/**
 * The late-return position of one rental. PURE and READ-ONLY.
 *
 * CONFIRMED RULE: if the customer does not bring the device back by the
 * contractual end date, the rental is late. Late days are charged at the
 * applicable rental daily cost PLUS 15%. Nothing else: no other penalty, no
 * escalation, no interest, no deadline.
 *
 * DAY COUNT. Dates are inclusive at both ends everywhere in this project, so
 * the contract covers through `end_date` and the first late day is the day
 * after it. Late days are therefore `returned_on - end_date` in whole days:
 * returned on the end date = 0, the next day = 1, and so on. Before the end
 * date has passed there are no late days at all, so an on-time or early return
 * can never produce a charge. While the device is still out the count runs
 * against today and is NOT final -- it grows until the device comes back, and
 * the actual return evidence (`returned_on`, written only from a completed
 * customer-return handover) is what settles it.
 *
 * THE DAILY COST is `rental_reservations.daily_rate`: the per-day rental price
 * snapshotted onto the reservation when the customer booked. It is the existing
 * pricing model's own representation of "the rental daily cost", so no new
 * price model is invented here. What it deliberately does NOT include is the
 * extra-controller fee and the duration discount -- both of those are terms of
 * the agreed rental period, and whether they extend into a late period is a
 * commercial decision nobody has made. See docs/business/CONFIRMED_DECISIONS.md.
 *
 * WHERE THE MONEY GOES IS UNDECIDED. The 35/65 split is defined on
 * `rental_total`, and no confirmed rule says whether a late fee belongs to the
 * owner, to GamePek, or is split. So this class CALCULATES and nothing more:
 * it writes no ledger entry, touches no settlement and is never added to
 * `rental_total`. RentalSettlementService audits the open question
 * (`settlement.late_fee_undistributed`) when it settles a late rental.
 */
final class LateReturn
{
    /** CONFIRMED: late days cost the daily rate plus 15%. 15% in basis points. */
    public const SURCHARGE_BPS = 1500;

    private function __construct(
        /** The device came back after the contractual end date, or is still out past it. */
        public readonly bool $isLate,
        /** Late and the device has not been received back yet: the count is still growing. */
        public readonly bool $stillOut,
        public readonly int $lateDays,
        public readonly int $dailyRate,
        /** lateDays x dailyRate, before the surcharge. */
        public readonly int $baseAmount,
        /** 15% of the base, rounded down (as the settlement split rounds). */
        public readonly int $surcharge,
        public readonly int $total,
    ) {}

    public static function for(RentalReservation $reservation, ?string $today = null): self
    {
        return self::of(
            $reservation->end_date->toDateString(),
            $reservation->returned_on?->toDateString(),
            (int) $reservation->daily_rate,
            $today,
        );
    }

    /**
     * @param  string  $endDate  the contractual last day, never modified by lateness
     * @param  string|null  $returnedOn  the day GamePek actually received the device
     * @param  int  $dailyRate  the reservation's own daily rental price
     */
    public static function of(string $endDate, ?string $returnedOn, int $dailyRate, ?string $today = null): self
    {
        $today ??= now()->toDateString();
        $against = $returnedOn ?? $today;

        // Whole days past the contractual end. Both operands are civil dates,
        // so this is a calendar-day difference, never an elapsed-hours one.
        $lateDays = max(0, (int) Carbon::parse($endDate)
            ->startOfDay()
            ->diffInDays(Carbon::parse($against)->startOfDay(), absolute: false));

        $dailyRate = max(0, $dailyRate);

        if ($lateDays === 0) {
            return new self(false, false, 0, $dailyRate, 0, 0, 0);
        }

        $base = $lateDays * $dailyRate;
        $surcharge = intdiv($base * self::SURCHARGE_BPS, 10_000);

        return new self(
            isLate: true,
            stillOut: $returnedOn === null,
            lateDays: $lateDays,
            dailyRate: $dailyRate,
            baseAmount: $base,
            surcharge: $surcharge,
            total: $base + $surcharge,
        );
    }
}
