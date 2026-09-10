<?php

namespace App\Enums;

/**
 * The kind of physical work an operational task represents.
 *
 * SCOPE: exactly one type is executable today -- OwnerDevicePickup. Delivery,
 * customer return, owner return and inspection are real future operations, and
 * they are deliberately ABSENT rather than declared-and-disabled: an enum case
 * that cannot be produced is a promise the code does not keep, and the audit
 * found that pattern in this project already.
 */
enum RentalOperationType: string
{
    /**
     * GamePek must obtain a third-party owner's physical console before the
     * rental can proceed. GamePek-owned stock needs no such task -- see
     * RentalOperationService and DeviceCustodyService.
     */
    case OwnerDevicePickup = 'owner_device_pickup';

    public function label(): string
    {
        return match ($this) {
            self::OwnerDevicePickup => 'تحویل گرفتن دستگاه از مالک',
        };
    }
}
