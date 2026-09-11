<?php

namespace App\Enums;

/**
 * Where the physical promissory note is -- derived from guarantee_note_events.
 */
enum GuaranteeNoteStatus: string
{
    case NotReceived = 'not_received';
    case HeldByGamePek = 'held_by_gamepek';
    case ReturnedToCustomer = 'returned_to_customer';
    case TransferredToOwner = 'transferred_to_owner';
    case RetainedByGamePek = 'retained_by_gamepek';

    public function label(): string
    {
        return match ($this) {
            self::NotReceived => 'هنوز دریافت نشده',
            self::HeldByGamePek => 'نزد گیم‌پک',
            self::ReturnedToCustomer => 'به مشتری بازگردانده شد',
            self::TransferredToOwner => 'برای پیگیری به مالک دستگاه تحویل شد',
            self::RetainedByGamePek => 'به دلیل پرداخت نشدن خسارت نزد گیم‌پک ماند',
        };
    }

    /** A final outcome was recorded. A merely held note is not resolved. */
    public function isResolved(): bool
    {
        return in_array($this, [self::ReturnedToCustomer, self::TransferredToOwner, self::RetainedByGamePek], true);
    }
}
