<?php

namespace App\Enums;

/**
 * A held date range on one product.
 *
 * How long a Held reservation survives before Expired is TODO(business) B10:
 * config('rental.reservation.hold_minutes') defaults to null, and the expiry
 * command no-ops while it is null -- a reservation is never silently dropped
 * on a guessed timeout.
 */
enum ReservationState: string
{
    case Draft = 'draft';
    case Held = 'held';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Active = 'active';
    case Returned = 'returned';
    case Closed = 'closed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Held, self::Cancelled],
            self::Held => [self::AwaitingPayment, self::Cancelled, self::Expired],
            self::AwaitingPayment => [self::Paid, self::Cancelled, self::Expired],
            self::Paid => [self::Active, self::Cancelled],
            self::Cancelled => [],
            self::Expired => [],
            self::Active => [self::Returned],
            self::Returned => [self::Closed],
            self::Closed => [],
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
            self::Held => 'رزرو شده',
            self::AwaitingPayment => 'در انتظار پرداخت',
            self::Paid => 'پرداخت شده',
            self::Cancelled => 'لغو شده',
            self::Expired => 'منقضی شده',
            self::Active => 'در حال اجاره',
            self::Returned => 'بازگردانده شده',
            self::Closed => 'بسته شده',
        };
    }
}
