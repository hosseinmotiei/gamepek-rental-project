<?php

namespace App\Enums;

/**
 * Verification media (identity photos, liveness and handover video).
 *
 * Purged is terminal and keeps the row: the file is gone but the audit trail
 * of it having existed must survive. Retention per kind is TODO(business) B11.
 */
enum MediaState: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Rejected = 'rejected';
    case Purged = 'purged';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Uploaded => [self::Processing, self::Rejected],
            self::Processing => [self::Ready, self::Rejected],
            self::Ready => [self::Rejected, self::Purged],
            self::Rejected => [self::Purged],
            self::Purged => [],
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
            self::Uploaded => 'بارگذاری شده',
            self::Processing => 'در حال پردازش',
            self::Ready => 'آماده',
            self::Rejected => 'رد شده',
            self::Purged => 'حذف شده طبق سیاست نگهداری',
        };
    }
}
