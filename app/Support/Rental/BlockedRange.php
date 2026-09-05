<?php

namespace App\Support\Rental;

use InvalidArgumentException;

/**
 * An inclusive span of days during which an item cannot be rented.
 *
 * The `Grok-show` prototype carried these as loose `{from, to, kind}`
 * objects in static data. As a value object the date ordering can be
 * checked once at construction instead of being re-assumed by every caller
 * that iterates a range.
 *
 * `kind` distinguishes a confirmed booking from one still awaiting
 * approval, which is what lets the calendar colour the two differently.
 * Where a range originates — a reservation, an owner blackout, a
 * maintenance window — is not decided here.
 */
class BlockedRange
{
    public const KIND_RESERVED = Availability::KIND_RESERVED;

    public const KIND_PENDING = Availability::KIND_PENDING;

    /**
     * @param  string  $from  inclusive ISO `Y-m-d` start
     * @param  string  $to  inclusive ISO `Y-m-d` end
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $kind = self::KIND_RESERVED,
    ) {
        if (Jalali::compare($from, $to) > 0) {
            throw new InvalidArgumentException("Blocked range start ({$from}) is after its end ({$to}).");
        }

        if (! in_array($kind, [self::KIND_RESERVED, self::KIND_PENDING], true)) {
            throw new InvalidArgumentException("Unknown blocked range kind: {$kind}.");
        }
    }

    public function covers(string $iso): bool
    {
        return Jalali::compare($iso, $this->from) >= 0
            && Jalali::compare($iso, $this->to) <= 0;
    }
}
