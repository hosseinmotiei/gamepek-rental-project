<?php

namespace App\Services\Rental;

use App\Models\RentalReservation;
use App\Support\Rental\BlockedRange;
use Illuminate\Database\Eloquent\Builder;

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
