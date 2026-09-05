<?php

namespace App\Enums;

/**
 * KYC level-2 identity state.
 *
 * Failed is retryable (back to Submitted); Rejected is an admin decision and
 * terminal. ManualReview exists because config('verification.identity.min_score')
 * defaults to null -- with no owner-approved threshold, a scored check can never
 * auto-pass, it routes to a human. See TODO(business) B2.
 */
enum IdentityState: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Checking = 'checking';
    case ManualReview = 'manual_review';
    case Verified = 'verified';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Rejected],
            self::Submitted => [self::Checking, self::Rejected],
            self::Checking => [self::Verified, self::Failed, self::ManualReview],
            self::ManualReview => [self::Verified, self::Rejected],
            self::Verified => [],
            self::Failed => [self::Submitted],
            self::Rejected => [],
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
            self::Draft => 'پیش‌نویس',
            self::Submitted => 'ثبت شده',
            self::Checking => 'در حال استعلام',
            self::ManualReview => 'بررسی دستی',
            self::Verified => 'تأیید شده',
            self::Failed => 'ناموفق',
            self::Rejected => 'رد شده',
        };
    }
}
