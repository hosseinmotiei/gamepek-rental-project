<?php

namespace App\Enums;

/**
 * Who physically holds a device.
 *
 * CUSTODY IS NOT OWNERSHIP. An owner keeps `devices.owner_id` and
 * `devices.ownership` forever; custody says only who has the box right now.
 * Conflating the two is the specific mistake this domain exists to prevent.
 *
 * Customer is declared because custody genuinely has three parties and a
 * two-valued column would have to be widened later under live data. No customer
 * transfer is executable in this phase -- see CustodyTransferType.
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
