<?php

namespace App\Services\Rental;

use App\Models\RentalReservation;
use App\Support\Rental\BlockedRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The single authority on "is this product free between these two dates?".
 *
 * There used to be two answers and they contradicted each other: the search
 * filter queried `rental_reservations`, while the product page's calendar read
 * a static `products.attributes._rental.blocked` JSON array written by a
 * seeder. A device could show as reserved on its own page while being freely
 * bookable, and show as free while already paid for.
 *
 * Confirmed business rule C-18: availability is derived from actual rental
 * reservation state. `_rental.blocked` is no longer consulted for live
 * availability anywhere -- see RentalItem::blocked()'s docblock.
 *
 * Every caller -- search, the product calendar, reservation validation and the
 * post-payment reservation write -- goes through this class, so the overlap
 * predicate exists exactly once. Do not re-implement it in a controller, a
 * Blade template or JavaScript.
 *
 * Dates are inclusive on both ends, matching how a rental is counted:
 * `end = start + days - 1`.
 */
class RentalAvailabilityService
{
    /** C-04: the shortest rental is one day, and a 1-day rental occupies only its start date. */
    public const MIN_DAYS = 1;

    /**
     * Why this range cannot be BOOKED, or null when it can.
     *
     * Separate from isFree() on purpose, and the distinction matters:
     *
     *   isFree()              "is the inventory free?"  -- about OVERLAP
     *   bookingBlockedReason() "may a customer book it?" -- about TIME
     *
     * They are asked at different moments. isFree() is also called AFTER a
     * payment clears, to re-check the range under a lock before writing the
     * reservation. If the past-date rule lived in there, a payment that settled
     * slightly late -- a gateway retry, a reconciliation run, a booking that
     * starts today and clears just after midnight -- would find its own start
     * date in the past and refuse to create the reservation for money already
     * taken. So time rules live here and overlap rules live there.
     *
     * WHY THIS EXISTS AT ALL
     *
     * Three places already agreed that a past date is not bookable -- the
     * product calendar (Availability::dayKind returns KIND_PAST), the search
     * form (ProductController::rentalWindow rejects it) and the UI. The one
     * place that actually writes a booking did not: `start_date` was validated
     * as `['required', 'date']` and recordSelection() checked only the day
     * count and the overlap. A selection ten days in the past was accepted.
     *
     * That is the same class of bug Phase 02 removed, on the time axis instead
     * of the overlap axis: two answers to one question, and the authoritative
     * one was the permissive one. The rule now lives here, once, so a caller
     * cannot forget it.
     *
     * DATES, NOT TIMESTAMPS. `today` is the civil date in the application
     * timezone (Asia/Tehran), matching C-03: availability is date-based, never
     * hourly. A rental starting today is bookable all day.
     *
     * No upper bound on duration is imposed -- C-05 leaves the maximum
     * unlimited. config('rental.search.max_days') caps the SEARCH FORM only.
     */
    public function bookingBlockedReason(string $startDate, int $days, ?string $today = null): ?string
    {
        if ($days < self::MIN_DAYS) {
            return 'مدت اجاره باید حداقل یک روز باشد.';
        }

        try {
            $start = Carbon::parse($startDate)->toDateString();
        } catch (\Throwable) {
            return 'فرمت تاریخ نامعتبر است.';
        }

        $today ??= now()->toDateString();

        if ($start < $today) {
            return 'تاریخ شروع نمی‌تواند در گذشته باشد.';
        }

        return null;
    }

    /** Convenience wrapper. The reason is what callers should surface. */
    public function isBookableRange(string $startDate, int $days, ?string $today = null): bool
    {
        return $this->bookingBlockedReason($startDate, $days, $today) === null;
    }

    /**
     * Is the product free for the whole inclusive range?
     *
     * Read-only. When this decides whether a reservation may be written, the
     * caller must hold a lock on the product row and call this INSIDE that
     * transaction -- otherwise two requests can both see "free" before either
     * writes. See RentalReservationService::materialiseAfterPayment().
     */
    public function isFree(
        int $productId,
        string $startDate,
        string $endDate,
        ?int $ignoreReservationId = null,
    ): bool {
        return ! RentalReservation::query()
            ->overlapping($productId, $startDate, $endDate)
            ->blocking()
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->exists();
    }

    /**
     * Constrains a Product query to rows that are free for the whole range.
     *
     * Used by the catalog search so the listing and the calendar cannot drift:
     * both resolve through `blocking()` on the same table.
     */
    public function constrainProductQuery(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereDoesntHave('rentalReservations', fn ($q) => $q
            ->blocking()
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate));
    }

    /**
     * The blocked ranges for one product, as the calendar renders them.
     *
     * Returns real reservations only. A range is reported as RESERVED when the
     * reservation is paid or active -- which, under C-15/C-16, is the only kind
     * of reservation that can exist. The PENDING kind is retained by
     * BlockedRange for legacy rows and is not produced here.
     *
     * @return list<BlockedRange>
     */
    public function blockedRangesFor(int $productId, ?string $from = null, ?string $to = null): array
    {
        $rows = RentalReservation::query()
            ->where('product_id', $productId)
            ->blocking()
            ->when($from && $to, fn ($q) => $q
                ->where('start_date', '<=', $to)
                ->where('end_date', '>=', $from))
            ->orderBy('start_date')
            ->get(['start_date', 'end_date']);

        $ranges = [];

        foreach ($rows as $row) {
            try {
                $ranges[] = new BlockedRange(
                    $row->start_date->toDateString(),
                    $row->end_date->toDateString(),
                    BlockedRange::KIND_RESERVED,
                );
            } catch (\Throwable) {
                // A malformed stored range must not break the product page.
                continue;
            }
        }

        return $ranges;
    }
}
