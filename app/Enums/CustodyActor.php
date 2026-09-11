<?php

namespace App\Enums;

/**
 * Who physically holds a device.
 *
 * CUSTODY IS NOT OWNERSHIP. An owner keeps `devices.owner_id` and
 * `devices.ownership` forever; custody says only who has the box right now.
 * Conflating the two is the specific mistake this domain exists to prevent.
 *
 * Custody genuinely has three parties. Which pairs of them may exchange a
 * device is fixed by CustodyTransferType and enforced by a CHECK constraint --
 * in particular a customer never hands a device straight to an owner.
 */
enum CustodyActor: string
{
    case Owner = 'owner';
    case GamePek = 'gamepek';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالک',
            self::GamePek => 'گیم‌پک',
            self::Customer => 'مشتری',
        };
    }
}
