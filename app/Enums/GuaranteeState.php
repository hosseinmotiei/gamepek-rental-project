<?php

namespace App\Enums;

/**
 * Cheque / promissory-note state.
 *
 * Nothing auto-promotes to Verified while
 * config('verification.guarantee.required_inquiries') is empty (its default) --
 * see TODO(business) B6. Until the owner names the mandatory inquiry set, a
 * guarantee reaching Inquiring waits for an admin.
 */
enum GuaranteeState: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Inquiring = 'inquiring';
    case Verified = 'verified';
    case OwnershipMismatch = 'ownership_mismatch';
    case RiskRejected = 'risk_rejected';
    case Rejected = 'rejected';
    case Released = 'released';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Submitted],
            self::Submitted => [self::Inquiring, self::Rejected],
            self::Inquiring => [self::Verified, self::OwnershipMismatch, self::RiskRejected, self::Rejected],
            self::Verified => [self::Released],
            self::OwnershipMismatch => [self::Submitted],
            self::RiskRejected => [],
            self::Rejected => [],
            self::Released => [],
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
            self::Pending => 'در انتظار ثبت',
            self::Submitted => 'ثبت شده',
            self::Inquiring => 'در حال استعلام',
            self::Verified => 'تأیید شده',
            self::OwnershipMismatch => 'مالکیت مطابقت ندارد',
            self::RiskRejected => 'رد به دلیل ریسک اعتباری',
            self::Rejected => 'رد شده',
            self::Released => 'آزاد شده',
        };
    }
}
