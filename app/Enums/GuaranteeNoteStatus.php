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

    /** An outcome was recorded. A merely held note is not resolved. */
    public function isResolved(): bool
    {
        return in_array($this, [self::ReturnedToCustomer, self::TransferredToOwner, self::RetainedByGamePek], true);
    }

    /**
     * The note has physically left GamePek's hands, so nothing further can be
     * recorded about it and the customer can no longer settle the damage here.
     *
     * CONFIRMED: retention is NOT terminal. A GamePek-owned device has no owner
     * to hand the note to, so GamePek keeps it while the damage is unpaid --
     * the customer may still pay afterwards, and the note then goes back to
     * them. Only a return or an owner transfer ends the story.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::ReturnedToCustomer, self::TransferredToOwner], true);
    }
}
