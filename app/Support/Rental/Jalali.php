<?php

namespace App\Support\Rental;

/**
 * Jalali (Persian) calendar conversion and formatting.
 *
 * Ported from the `Grok-show` rental prototype (`src/lib/rental/jalali.ts`),
 * which used the standard Borkowski/jalaali algorithm. The arithmetic is
 * carried over unchanged: PHP's intdiv() and % both truncate toward zero,
 * matching the JavaScript Math.trunc()/% the original relied on, so the
 * conversions produce identical results.
 *
 * Dates are passed around as ISO `Y-m-d` strings rather than Carbon
 * instances because rental availability is a whole-day question — a
 * reservation covers a day, not an instant — and string dates compare and
 * serialise without dragging timezone semantics into the domain.
 *
 * This is calendar arithmetic only. It deliberately contains no rental
 * policy: what counts as a bookable day is decided in Availability.
 */
class Jalali
{
    public const MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /** Weekday labels starting Saturday, matching isoWeekdaySat0(). */
    public const WEEKDAYS_SHORT = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    /**
     * Cumulative days before each Gregorian month in a non-leap year.
     */
    private const GREGORIAN_MONTH_OFFSETS = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    /**
     * Leap-year cycle breakpoints for the 33-year Jalali cycle.
     */
    private const LEAP_BREAKS = [
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097,
        2192, 2262, 2324, 2394, 2456, 3178,
    ];

    /**
     * @return array{jy: int, jm: int, jd: int}
     */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $gy2 = $gm > 2 ? $gy + 1 : $gy;

        $days = 355666
            + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd
            + self::GREGORIAN_MONTH_OFFSETS[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return ['jy' => $jy, 'jm' => $jm, 'jd' => $jd];
    }

    /**
     * @return array{gy: int, gm: int, gd: int}
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;

        $days = -355668
            + (365 * $jy)
            + (intdiv($jy, 33) * 8)
            + intdiv(($jy % 33) + 3, 4)
            + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;

        $monthLengths = [
            31,
            (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0) ? 29 : 28,
            31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
        ];

        $gm = 1;
        foreach ($monthLengths as $length) {
            if ($gd <= $length) {
                break;
            }
            $gd -= $length;
            $gm++;
        }

        return ['gy' => $gy, 'gm' => $gm, 'gd' => $gd];
    }

    public static function isLeapYear(int $jy): bool
    {
        $jp = self::LEAP_BREAKS[0];
        $jump = 0;

        $count = count(self::LEAP_BREAKS);
        for ($i = 1; $i < $count; $i++) {
            $jm = self::LEAP_BREAKS[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $jp = $jm;
        }

        $n = $jy - $jp;
        if ($jump - $n < 6) {
            $n = $n - $jump + (intdiv($jump + 4, 33) * 33);
        }

        $leap = ((($n + 1) % 33) - 1) % 4;
        if ($leap === -1) {
            $leap = 4;
        }

        return $leap === 0;
    }

    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }

        if ($jm <= 11) {
            return 30;
        }

        return self::isLeapYear($jy) ? 30 : 29;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ISO date helpers
    // ─────────────────────────────────────────────────────────────────────

    public static function isoFromParts(int $y, int $m, int $d): string
    {
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * @return array{y: int, m: int, d: int}
     */
    public static function parseIso(string $iso): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $iso));

        return ['y' => $y, 'm' => $m, 'd' => $d];
    }

    /**
     * @return array{jy: int, jm: int, jd: int}
     */
    public static function isoToJalali(string $iso): array
    {
        $g = self::parseIso($iso);

        return self::fromGregorian($g['y'], $g['m'], $g['d']);
    }

    /**
     * Human-readable Jalali date, e.g. "۱۴۰۵/۰۶/۱۴" rendered as "14 شهریور 1405".
     *
     * Added for the rental contract and the verification screens, which have to
     * show dates to customers; the conversion itself was already here.
     */
    public static function format(string $iso): string
    {
        $j = self::isoToJalali($iso);

        return sprintf('%d %s %d', $j['jd'], self::MONTHS[$j['jm'] - 1], $j['jy']);
    }

    public static function jalaliToIso(int $jy, int $jm, int $jd): string
    {
        $g = self::toGregorian($jy, $jm, $jd);

        return self::isoFromParts($g['gy'], $g['gm'], $g['gd']);
    }

    public static function addDays(string $iso, int $days): string
    {
        $p = self::parseIso($iso);
        $ts = gmmktime(0, 0, 0, $p['m'], $p['d'] + $days, $p['y']);

        return gmdate('Y-m-d', $ts);
    }

    public static function diffDays(string $from, string $to): int
    {
        $a = self::parseIso($from);
        $b = self::parseIso($to);

        $da = gmmktime(0, 0, 0, $a['m'], $a['d'], $a['y']);
        $db = gmmktime(0, 0, 0, $b['m'], $b['d'], $b['y']);

        return intdiv($db - $da, 86400);
    }

    /**
     * Weekday index with Saturday as 0, matching the Persian week and the
     * WEEKDAYS_SHORT ordering above.
     */
    public static function weekdaySat0(string $iso): int
    {
        $p = self::parseIso($iso);
        $dow = (int) gmdate('w', gmmktime(0, 0, 0, $p['m'], $p['d'], $p['y']));

        return ($dow + 1) % 7;
    }

    /**
     * ISO `Y-m-d` strings are lexicographically ordered, so a plain string
     * comparison is also a chronological one.
     */
    public static function compare(string $a, string $b): int
    {
        return $a <=> $b;
    }

    /**
     * Every ISO day from $from to $to inclusive.
     *
     * @return list<string>
     */
    public static function eachDay(string $from, string $to): array
    {
        $out = [];
        $cur = $from;

        while (self::compare($cur, $to) <= 0) {
            $out[] = $cur;
            $cur = self::addDays($cur, 1);
        }

        return $out;
    }

    /**
     * Persian digits without thousands grouping.
     *
     * The global persian_number() helper runs the value through
     * number_format(), which would render a year as "۱٬۴۰۵". Calendar
     * numbers are never grouped, so digits are mapped directly here.
     */
    public static function toPersianDigits(int|string $value): string
    {
        return strtr((string) $value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    /**
     * "۱۲ شهریور ۱۴۰۵" — Persian digits, per the public UI convention.
     */
    public static function formatLong(string $iso): string
    {
        $j = self::isoToJalali($iso);

        return self::toPersianDigits($j['jd'])
            .' '.self::MONTHS[$j['jm'] - 1]
            .' '.self::toPersianDigits($j['jy']);
    }
}
