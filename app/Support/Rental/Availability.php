<?php

namespace App\Support\Rental;

/**
 * Interval-overlap availability for a rental item.
 *
 * Ported from the `Grok-show` rental prototype
 * (`src/lib/rental/availability.ts`). This is the calculation the
 * `Product::isInStock()` seam is expected to grow into: "is this device free
 * between these two dates?" rather than a scalar stock count.
 *
 * Deliberately pure. It takes blocked ranges as an argument and never
 * queries — where blocked ranges come from (a reservations table, an owner's
 * blackout calendar, a maintenance window) is a rental domain decision that
 * has not been made yet, so this class must not assume one.
 *
 * The prototype hardcoded a `DEMO_TODAY` constant so its static demo data
 * would always look bookable. That is not carried over: `$today` defaults to
 * the real current date and is injectable so tests can pin it.
 */
class Availability
{
    public const KIND_PAST = 'past';

    public const KIND_AVAILABLE = 'available';

    public const KIND_RESERVED = 'reserved';

    public const KIND_PENDING = 'pending';

    /**
     * Classify a single day.
     *
     * A day already blocked is reported with the blocking range's own kind
     * (reserved / pending) so the calendar can distinguish a confirmed
     * booking from one still awaiting approval.
     *
     * @param  list<BlockedRange>  $blocked
     */
    public static function dayKind(string $iso, array $blocked, ?string $today = null): string
    {
        $today ??= self::today();

        if (Jalali::compare($iso, $today) < 0) {
            return self::KIND_PAST;
        }

        foreach ($blocked as $range) {
            if ($range->covers($iso)) {
                return $range->kind;
            }
        }

        return self::KIND_AVAILABLE;
    }

    /**
     * Is every day of a $days-long rental starting on $start bookable?
     *
     * $days is inclusive of the start day: a 1-day rental occupies only
     * $start.
     *
     * @param  list<BlockedRange>  $blocked
     */
    public static function rangeIsFree(string $start, int $days, array $blocked, ?string $today = null): bool
    {
        if ($days < 1) {
            return false;
        }

        $today ??= self::today();
        $end = Jalali::addDays($start, $days - 1);

        foreach (Jalali::eachDay($start, $end) as $iso) {
            if (self::dayKind($iso, $blocked, $today) !== self::KIND_AVAILABLE) {
                return false;
            }
        }

        return true;
    }

    /**
     * The soonest free window of $days length, scanning forward from today.
     *
     * Powers the "nearest available range" suggestion shown when a customer
     * picks a window that is already taken. Returns null if nothing fits
     * inside $horizon days.
     *
     * @param  list<BlockedRange>  $blocked
     * @return array{start: string, end: string}|null
     */
    public static function findNearestRange(
        int $days,
        array $blocked,
        ?string $today = null,
        int $horizon = 60,
    ): ?array {
        if ($days < 1) {
            return null;
        }

        $today ??= self::today();

        for ($i = 0; $i < $horizon; $i++) {
            $start = Jalali::addDays($today, $i);

            if (self::rangeIsFree($start, $days, $blocked, $today)) {
                return [
                    'start' => $start,
                    'end' => Jalali::addDays($start, $days - 1),
                ];
            }
        }

        return null;
    }

    private static function today(): string
    {
        return now()->toDateString();
    }
}
