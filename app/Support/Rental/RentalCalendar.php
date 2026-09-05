<?php

namespace App\Support\Rental;

/**
 * Builds one month of availability cells for the product page calendar.
 *
 * The prototype rendered its calendar client-side from a hardcoded product
 * array. Here the classification is done server-side through Availability, so
 * the calendar and the price a customer is quoted are derived from the same
 * authority — a day the calendar paints as free is a day the backend agrees
 * is bookable.
 *
 * Jalali (Persian) months are the unit customers think in, so the grid is
 * built over a Jalali month, not a Gregorian one.
 */
class RentalCalendar
{
    /**
     * One Jalali month of cells starting from today's month.
     *
     * `lead` is the count of blank cells before day 1 so the first day lands
     * under its correct weekday column (Saturday-first, matching the Persian
     * week).
     *
     * @param  list<BlockedRange>  $blocked
     * @return array{monthLabel: string, lead: list<int>, days: list<array{iso: string, dayNumber: string, kind: string, label: string}>}
     */
    public static function build(array $blocked, ?string $today = null): array
    {
        $today ??= now()->toDateString();

        ['jy' => $jy, 'jm' => $jm] = Jalali::isoToJalali($today);

        $length = Jalali::monthLength($jy, $jm);
        $firstIso = Jalali::jalaliToIso($jy, $jm, 1);

        $days = [];

        for ($d = 1; $d <= $length; $d++) {
            $iso = Jalali::jalaliToIso($jy, $jm, $d);

            $days[] = [
                'iso' => $iso,
                'dayNumber' => Jalali::toPersianDigits($d),
                'kind' => Availability::dayKind($iso, $blocked, $today),
                'label' => Jalali::formatLong($iso),
            ];
        }

        return [
            'monthLabel' => Jalali::MONTHS[$jm - 1].' '.Jalali::toPersianDigits($jy),
            'lead' => array_fill(0, Jalali::weekdaySat0($firstIso), 0),
            'days' => $days,
        ];
    }

    /**
     * The soonest window of $days consecutive free days, formatted for
     * display, or null if none falls inside the search horizon.
     *
     * @param  list<BlockedRange>  $blocked
     * @return array{start: string, end: string, label: string}|null
     */
    public static function suggestion(int $days, array $blocked, ?string $today = null): ?array
    {
        $range = Availability::findNearestRange($days, $blocked, $today);

        if ($range === null) {
            return null;
        }

        return $range + [
            'label' => Jalali::formatLong($range['start']).' تا '.Jalali::formatLong($range['end']),
        ];
    }
}
