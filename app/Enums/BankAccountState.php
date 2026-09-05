<?php

namespace App\Enums;

/**
 * Ownership state of one card or IBAN.
 *
 * Mismatch means the provider answered and the holder is not this user --
 * a definite negative. Failed means the provider could not answer. Both are
 * retryable; only Verified unlocks the chain.
 */
enum BankAccountState: string
{
    case Pending = 'pending';
    case Inquiring = 'inquiring';
    case Verified = 'verified';
    case Mismatch = 'mismatch';
    case Failed = 'failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Inquiring],
            self::Inquiring => [self::Verified, self::Mismatch, self::Failed],
            self::Verified => [],
            self::Mismatch => [self::Pending],
            self::Failed => [self::Pending],
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
            self::Pending => 'در انتظار استعلام',
            self::Inquiring => 'در حال استعلام',
            self::Verified => 'مالکیت تأیید شد',
            self::Mismatch => 'مالکیت مطابقت ندارد',
            self::Failed => 'استعلام ناموفق',
        };
    }
}
