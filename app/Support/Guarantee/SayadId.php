<?php

namespace App\Support\Guarantee;

/**
 * Pure local validation of a 16-digit Sayad cheque id (شناسه صیاد).
 *
 * This runs BEFORE any paid inquiry, so a mistyped id never costs a call. It
 * proves nothing about the cheque itself -- only that the number is
 * structurally well formed.
 *
 * Algorithm: cycling positional weights 32/16/8/4/2 over the first 15 digits,
 * mod 11, check digit last.
 *
 * TODO(integration): this is the widely-published form of the Sayad check
 * digit, but no bank specification is available in this project to confirm it.
 * Until one is, `config('verification.guarantee.enforce_sayad_checksum')`
 * defaults to false and isValid() applies only the structural rules -- because
 * rejecting a customer's genuine cheque on an unverified checksum is worse
 * than passing a malformed id to the provider, which will reject it anyway.
 * Flip the config to true once the algorithm is confirmed.
 */
class SayadId
{
    private const WEIGHTS = [32, 16, 8, 4, 2, 32, 16, 8, 4, 2, 32, 16, 8, 4, 2];

    public static function normalise(string $sayadId): string
    {
        return preg_replace('/\D/', '', $sayadId) ?? '';
    }

    public static function isValid(string $sayadId): bool
    {
        $sayadId = self::normalise($sayadId);

        if (strlen($sayadId) !== 16) {
            return false;
        }

        // An all-identical string passes many weighted checksums by accident.
        if (preg_match('/^(\d)\1{15}$/', $sayadId)) {
            return false;
        }

        if (! config('verification.guarantee.enforce_sayad_checksum', false)) {
            return true;
        }

        return self::passesChecksum($sayadId);
    }

    /** The unconfirmed check-digit rule. See the class docblock. */
    public static function passesChecksum(string $sayadId): bool
    {
        $sayadId = self::normalise($sayadId);

        if (strlen($sayadId) !== 16) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $sum += ((int) $sayadId[$i]) * self::WEIGHTS[$i];
        }

        $remainder = $sum % 11;
        $checkDigit = $remainder < 2 ? 0 : 11 - $remainder;

        return $checkDigit === (int) $sayadId[15];
    }

    public static function mask(string $sayadId): string
    {
        $sayadId = self::normalise($sayadId);

        if (strlen($sayadId) !== 16) {
            return $sayadId;
        }

        return substr($sayadId, 0, 4).'********'.substr($sayadId, -4);
    }
}
