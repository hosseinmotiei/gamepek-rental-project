<?php

namespace App\Enums;

/**
 * One outbound SMS. Sent means the provider accepted it; Delivered means the
 * handset confirmed it (SMS-03), which most providers report asynchronously.
 */
enum SmsState: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Sending, self::Cancelled],
            self::Sending => [self::Sent, self::Failed],
            self::Sent => [self::Delivered, self::Failed],
            self::Delivered => [],
            self::Failed => [self::Queued],
            self::Cancelled => [],
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
            self::Queued => 'در صف ارسال',
            self::Sending => 'در حال ارسال',
            self::Sent => 'ارسال شده',
            self::Delivered => 'تحویل داده شده',
            self::Failed => 'ناموفق',
            self::Cancelled => 'لغو شده',
        };
    }
}
