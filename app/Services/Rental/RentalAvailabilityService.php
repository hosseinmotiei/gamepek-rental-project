<?php

namespace App\Services\Rental;

use App\Models\Device;
use App\Models\RentalReservation;
use App\Support\Rental\BlockedRange;
use App\Support\Rental\DeviceAssignmentFeasibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The single authority on "can this product be rented between these dates?".
 *
 * CONFIRMED MODEL: capacity is PHYSICAL DEVICES. A product with three eligible
 * devices can be rented to three customers at once; a fourth overlapping
 * request is refused; a product with no eligible device is never available.
 * Earlier this answered "any overlapping reservation blocks the whole
 * product", which assumed one device per product.
 *
 * "Available" means: after adding the requested range, every blocking
 * reservation of the product can still be given its own device, respecting
 * the devices already attached (DeviceAssignmentFeasibility). A reservation's
 * blocking period ends at the customer's ACTUAL return when that was early
 * (RentalReservation::blockedUntil), so an early-returned device is free again
 * for its remaining days. CONFIRMED late-return rule, the mirror image: a
 * device still with the customer past the contractual end date is NOT released
 * on that date -- it stays unavailable until the physical return is recorded,
 * and becomes free the day after it. Only reservations that block (paid/active)
 * count; an unpaid application blocks nothing.
 *
 * Eligible device = belongs to the product and is approved (Device::rentable).
 * Nothing here chooses or ranks a device, and nothing reads `_rental.blocked`.
 *
 * Dates are inclusive on both ends: `end = start + days - 1`.
 */
class RentalAvailabilityService
{
    /** C-04: the shortest rental is one day, and a 1-day rental occupies only its start date. */
    public const MIN_DAYS = 1;

    /** How far ahead the product calendar is computed when no window is given. */
    public const CALENDAR_DAYS = 180;

    /**
     * Why this range cannot be BOOKED, or null when it can.
     *
     * Separate from isFree() on purpose: this is about TIME (past dates, day
     * count), isFree() about CAPACITY. isFree() is re-asked after a payment
     * clears, and must not refuse a paid booking whose start date slipped into
     * the past while the gateway retried.
     *
     * DATES, NOT TIMESTAMPS. `today` is the civil date in the application
     * timezone. No upper bound on duration (C-05).
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
     * Is there physical capacity for one more reservation over the range?
     *
     * Read-only. When this decides whether a reservation may be written, the
     * caller must hold a lock on the product row and call this INSIDE that
     * transaction -- see RentalReservationService::materialiseAfterPayment().
     */
    public function isFree(
        int $productId,
        string $startDate,
        string $endDate,
        ?int $ignoreReservationId = null,
    ): bool {
        [$intervals, $eligible] = $this->state($productId, min($startDate, now()->toDateString()), $ignoreReservationId);

        if ($eligible === []) {
            return false;
        }

        $intervals[] = ['id' => 'requested', 'start' => $startDate, 'end' => $endDate, 'device' => null];

        return DeviceAssignmentFeasibility::isFeasible(
            DeviceAssignmentFeasibility::componentOf($intervals, 'requested'),
            $eligible,
        );
    }

    /**
     * Would attaching $deviceId to this reservation still leave every other
     * reservation of the product assignable? Used by attachDevice() under its
     * locks, so a manual choice can never strand another paid customer.
     */
    public function attachmentKeepsEveryReservationAssignable(RentalReservation $reservation, int $deviceId): bool
    {
        [$intervals, $eligible] = $this->state(
            $reservation->product_id,
            min($reservation->start_date->toDateString(), now()->toDateString()),
            null,
        );

        $found = false;

        foreach ($intervals as $i => $interval) {
            if ($interval['id'] === $reservation->id) {
                $intervals[$i]['device'] = $deviceId;
                $found = true;
            }
        }

        if (! $found) {
            // Not blocking any more (e.g. returned): nothing to protect.
            return true;
        }

        return DeviceAssignmentFeasibility::isFeasible(
            DeviceAssignmentFeasibility::componentOf($intervals, $reservation->id),
            $eligible,
        );
    }

    /**
     * Can the product's current and future bookings all be served? False is
     * an impossible state (reported by the reconciler), never a customer answer.
     */
    public function isCurrentlyAssignable(int $productId): bool
    {
        [$intervals, $eligible] = $this->state($productId, now()->toDateString(), null);

        foreach (DeviceAssignmentFeasibility::components($intervals) as $group) {
            if (! DeviceAssignmentFeasibility::isFeasible($group, $eligible)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Constrains a Product query to rows with capacity for the whole range.
     * Routed through isFree() so search and the calendar cannot disagree.
     */
    public function constrainProductQuery(Builder $query, string $startDate, string $endDate): Builder
    {
        $withDevices = Device::query()->rentable()->distinct()->pluck('product_id')->all();

        $free = array_values(array_filter(
            $withDevices,
            fn (int $productId) => $this->isFree($productId, $startDate, $endDate),
        ));

        return $query->whereIn($query->getModel()->getQualifiedKeyName(), $free);
    }

    /**
     * The days on which the product has NO capacity, as calendar ranges.
     *
     * A day is blocked when a one-day booking on it would be refused -- all
     * eligible devices are taken (or there are none). Consecutive blocked days
     * are merged. Defaults to today .. today + CALENDAR_DAYS.
     *
     * @return list<BlockedRange>
     */
    public function blockedRangesFor(int $productId, ?string $from = null, ?string $to = null): array
    {
        $from ??= now()->toDateString();
        $to ??= now()->addDays(self::CALENDAR_DAYS - 1)->toDateString();

        [$intervals, $eligible] = $this->state($productId, min($from, now()->toDateString()), null);

        $ranges = [];
        $runStart = null;
        $previous = null;

        for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day->addDay()) {
            $iso = $day->toDateString();
            $candidate = [...$intervals, ['id' => 'day', 'start' => $iso, 'end' => $iso, 'device' => null]];

            $full = $eligible === [] || ! DeviceAssignmentFeasibility::isFeasible(
                DeviceAssignmentFeasibility::componentOf($candidate, 'day'),
                $eligible,
            );

            if ($full && $runStart === null) {
                $runStart = $iso;
            }

            if (! $full && $runStart !== null) {
                $ranges[] = new BlockedRange($runStart, $previous, BlockedRange::KIND_RESERVED);
                $runStart = null;
            }

            $previous = $iso;
        }

        if ($runStart !== null) {
            $ranges[] = new BlockedRange($runStart, $previous, BlockedRange::KIND_RESERVED);
        }

        return $ranges;
    }

    /**
     * The product's blocking reservations still occupying a device on or
     * after $from, and its eligible devices.
     *
     * @return array{0: list<array{id: int|string, start: string, end: string, device: ?int}>, 1: list<int>}
     */
    private function state(int $productId, string $from, ?int $ignoreReservationId): array
    {
        $eligible = Device::query()
            ->where('product_id', $productId)
            ->rentable()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $intervals = RentalReservation::query()
            ->overlapping($productId, $from, '9999-12-31')
            ->blocking()
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->get(['id', 'start_date', 'end_date', 'returned_on', 'device_id', 'rental_application_id'])
            ->map(fn (RentalReservation $r) => [
                'id' => $r->id,
                'start' => $r->start_date->toDateString(),
                'end' => $r->blockedUntil()->toDateString(),
                'device' => $r->device_id,
            ])
            ->all();

        return [$intervals, $eligible];
    }
}
