<?php

namespace App\Enums;

/**
 * How far along one handover is.
 *
 *   requested     GamePek has asked the owner for the device. NOBODY has moved
 *                 anything; this is not possession.
 *   transferred   GamePek recorded taking physical possession. Custody moves
 *                 HERE and nowhere else.
 *   acknowledged  the counterparty (the owner) confirmed GamePek's record in
 *                 their own panel.
 *
 * `acknowledged` is a confirmation of a record by the other side. It is NOT a
 * signature, NOT legal acceptance, and NOT any statement about the condition of
 * the device. No legal effect is claimed or implemented: receipt and signature
 * requirements remain an open legal gate
 * (docs/business/CONFIRMED_DECISIONS.md section 4.2).
 */
enum CustodyTransferState: string
{
    case Requested = 'requested';
    case Transferred = 'transferred';
    case Acknowledged = 'acknowledged';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'درخواست شده',
            self::Transferred => 'تحویل گرفته شده',
            self::Acknowledged => 'تأییدشده توسط مالک',
        };
    }

    /** Has physical possession actually moved? `requested` means it has not. */
    public function isPossessionMoved(): bool
    {
        return $this === self::Transferred || $this === self::Acknowledged;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Transferred],
            self::Transferred => [self::Acknowledged],
            self::Acknowledged => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
