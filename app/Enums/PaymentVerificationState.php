<?php

namespace App\Enums;

/**
 * Result of the server-side PAY-03 verification of a transaction.
 *
 * Unknown is a real, distinct answer: Pardakht Novin documents no inquiry
 * operation, so status() cannot claim anything. It must never be treated as
 * either paid or unpaid.
 */
enum PaymentVerificationState: string
{
    case Unverified = 'unverified';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Mismatch = 'mismatch';
    case Unknown = 'unknown';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Unverified => [self::Verifying],
            self::Verifying => [self::Verified, self::Mismatch, self::Unknown],
            self::Verified => [],
            self::Mismatch => [],
            self::Unknown => [self::Verifying],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'تأیید نشده',
            self::Verifying => 'در حال تأیید',
            self::Verified => 'تأیید شده',
            self::Mismatch => 'مغایرت مبلغ یا وضعیت',
            self::Unknown => 'نامشخص',
        };
    }
}
