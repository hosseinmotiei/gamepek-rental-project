<?php

namespace App\Support\Banking;

/**
 * Pure, no dependencies -- same style as App\Support\Rental\*.
 *
 * A local format check is not ownership verification. It only stops obviously
 * mistyped numbers before an inquiry is paid for.
 */
class CardNumber
{
    public static function normalise(string $pan): string
    {
        return preg_replace('/\D/', '', $pan) ?? '';
    }

    public static function isValid(string $pan): bool
    {
        $pan = self::normalise($pan);

        if (strlen($pan) !== 16) {
            return false;
        }

        return self::passesLuhn($pan);
    }

    /** Luhn / mod-10, the standard PAN check digit. */
    public static function passesLuhn(string $pan): bool
    {
        $sum = 0;

        for ($i = 0; $i < strlen($pan); $i++) {
            $digit = (int) $pan[strlen($pan) - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
