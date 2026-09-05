<?php

namespace App\Enums;

/**
 * The rental chain, in order.
 *
 * Every state except the three terminal ones is *derived* from child-record
 * facts by RentalChainOrchestrator::nextState(), which is why `order()` exists:
 * the orchestrator walks the ladder and returns the highest state whose facts
 * hold. That makes advancing idempotent by construction.
 *
 * Approved / Rejected / Cancelled are the only non-derived edges -- they
 * require an explicit act, which is why canTransitionTo() guards them.
 */
enum RentalApplicationState: string
{
    case Draft = 'draft';
    case IdentityPending = 'identity_pending';
    case IdentityVerified = 'identity_verified';
    case BankPending = 'bank_pending';
    case BankVerified = 'bank_verified';
    case ReservationHeld = 'reservation_held';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case GuaranteePending = 'guarantee_pending';
    case GuaranteeVerified = 'guarantee_verified';
    case ContractGenerated = 'contract_generated';
    case ContractAccepted = 'contract_accepted';
    case ContractSigned = 'contract_signed';
    case AwaitingFinalApproval = 'awaiting_final_approval';
    case Approved = 'approved';
    case Active = 'active';
    case Returned = 'returned';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Position in the forward ladder. The three terminal states sit outside it
     * and return -1, so they never win a "highest reachable state" comparison.
     */
    public function order(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::IdentityPending => 1,
            self::IdentityVerified => 2,
            self::BankPending => 3,
            self::BankVerified => 4,
            self::ReservationHeld => 5,
            self::PaymentPending => 6,
            self::Paid => 7,
            self::GuaranteePending => 8,
            self::GuaranteeVerified => 9,
            self::ContractGenerated => 10,
            self::ContractAccepted => 11,
            self::ContractSigned => 12,
            self::AwaitingFinalApproval => 13,
            self::Approved => 14,
            self::Active => 15,
            self::Returned => 16,
            self::Closed => 17,
            self::Rejected, self::Cancelled => -1,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Closed], true);
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this->isTerminal()) {
            return false;
        }

        // Cancellation is allowed at any point before the rental is physically
        // in the customer's hands.
        if ($target === self::Cancelled) {
            return $this->order() < self::Active->order();
        }

        // Rejection is allowed at any point before final approval.
        if ($target === self::Rejected) {
            return $this->order() < self::Approved->order();
        }

        // Everything else moves strictly forward along the ladder.
        return $target->order() > $this->order();
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::IdentityPending => 'در انتظار احراز هویت',
            self::IdentityVerified => 'هویت تأیید شد',
            self::BankPending => 'در انتظار تأیید مالکیت بانکی',
            self::BankVerified => 'مالکیت بانکی تأیید شد',
            self::ReservationHeld => 'رزرو ثبت شد',
            self::PaymentPending => 'در انتظار پرداخت',
            self::Paid => 'پرداخت شد',
            self::GuaranteePending => 'در انتظار بررسی ضمانت',
            self::GuaranteeVerified => 'ضمانت تأیید شد',
            self::ContractGenerated => 'قرارداد صادر شد',
            self::ContractAccepted => 'قرارداد پذیرفته شد',
            self::ContractSigned => 'قرارداد امضا شد',
            self::AwaitingFinalApproval => 'در انتظار تأیید نهایی',
            self::Approved => 'تأیید نهایی شد',
            self::Active => 'اجاره فعال',
            self::Returned => 'بازگردانده شد',
            self::Closed => 'بسته شد',
            self::Rejected => 'رد شد',
            self::Cancelled => 'لغو شد',
        };
    }
}
