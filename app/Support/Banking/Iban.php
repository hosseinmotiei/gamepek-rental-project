<?php

namespace App\Support\Banking;

/**
 * Pure IBAN validation, Iranian form: IR + 24 digits (26 characters total).
 *
 * The old frontend prototype checked only that 24 digits had been typed. That
 * accepts a transposed pair of digits, which mod-97 catches.
 */
class Iban
{
    public static function normalise(string $iban): string
    {
        $iban = strtoupper(preg_replace('/[\s-]/', '', $iban) ?? '');

        // Customers routinely paste the 24 digits without the IR prefix.
        if (! str_starts_with($iban, 'IR')) {
            $iban = 'IR'.preg_replace('/\D/', '', $iban);
        }

        return $iban;
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalise($iban);

        if (! preg_match('/^IR\d{24}$/', $iban)) {
            return false;
        }

        return self::passesMod97($iban);
    }

    /** ISO 13616: move the first four characters to the end, letters to digits, mod 97 === 1. */
    public static function passesMod97(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // bcmod is not guaranteed to be installed, so reduce in chunks.
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ((string) $remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    public static function mask(string $iban): string
    {
        $iban = self::normalise($iban);

        if (strlen($iban) < 10) {
            return $iban;
        }

        return substr($iban, 0, 6).str_repeat('*', strlen($iban) - 10).substr($iban, -4);
    }
}
