<?php

namespace App\Support\Rental;

/**
 * The 35/65 split of one gross amount, in whole Toman.
 *
 * CONFIRMED (C-26 / C-27): GamePek 35%, owner 65%.
 *
 * ROUNDING -- a technical necessity, flagged for owner confirmation: amounts
 * are integers, so 35% of most amounts has a fractional part below one Toman.
 * GamePek's share is rounded DOWN and the owner receives the remainder, so the
 * two shares always add up to the gross exactly and the owner never receives
 * less than their exact 65%. The difference is at most one Toman per rental.
 */
final class SettlementSplit
{
    /** C-26: 35% in basis points. */
    public const GAMEPEK_COMMISSION_BPS = 3500;

    private function __construct(
        public readonly int $gross,
        public readonly int $gamepekShare,
        public readonly int $ownerShare,
        public readonly int $commissionBps,
    ) {}

    public static function of(int $gross): self
    {
        if ($gross < 0) {
            throw new \InvalidArgumentException('Settlement gross amount cannot be negative.');
        }

        $gamepek = intdiv($gross * self::GAMEPEK_COMMISSION_BPS, 10_000);

        return new self($gross, $gamepek, $gross - $gamepek, self::GAMEPEK_COMMISSION_BPS);
    }
}
