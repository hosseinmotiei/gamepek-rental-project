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

    public function label(): string
    {
        return match ($this) {
            self::NotReceived => 'هنوز دریافت نشده',
            self::HeldByGamePek => 'نزد گیم‌پک',
            self::ReturnedToCustomer => 'به مشتری بازگردانده شد',
            self::TransferredToOwner => 'برای پیگیری به مالک دستگاه تحویل شد',
        };
    }

    public function isResolved(): bool
    {
        return $this === self::ReturnedToCustomer || $this === self::TransferredToOwner;
    }
}
