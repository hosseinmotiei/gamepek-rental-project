<?php

namespace App\Enums;

/**
 * The kind of physical work an operational task represents.
 *
 * SCOPE: three types are executable. The owner pickup that has always existed,
 * plus the delivery and customer-return pair added once the business confirmed
 * that a rental starts at physical delivery to the customer and ends when the
 * customer returns the device (docs/operations/OPERATIONS_AND_CUSTODY.md §13).
 *
 * Owner return (GamePek handing the console back to its owner) and inspection
 * are confirmed to exist as business steps but have no service behind them, so
 * they remain ABSENT rather than declared-and-disabled: an enum case that
 * cannot be produced is a promise the code does not keep, and the audit found
 * that pattern in this project already.
 */
enum RentalOperationType: string
{
    /**
     * GamePek must obtain a third-party owner's physical console before the
     * rental can proceed. GamePek-owned stock needs no such task -- see
     * RentalOperationService and DeviceCustodyService.
     */
    case OwnerDevicePickup = 'owner_device_pickup';

    /**
     * GamePek delivers the device to the customer. Completing this task is the
     * ONLY thing that moves an approved application to Active -- a rental does
     * not start because its start date arrived.
     */
    case CustomerDelivery = 'customer_delivery';

    /**
     * The customer returns the device to GamePek. Arranged through support
     * rather than self-service, per the confirmed process.
     */
    case CustomerReturn = 'customer_return';

    public function label(): string
    {
        return match ($this) {
            self::OwnerDevicePickup => 'تحویل گرفتن دستگاه از مالک',
            self::CustomerDelivery => 'تحویل دستگاه به مشتری',
            self::CustomerReturn => 'بازگشت دستگاه از مشتری',
        };
    }

    /** The custody leg this task records when it completes. */
    public function custodyTransferType(): CustodyTransferType
    {
        return match ($this) {
            self::OwnerDevicePickup => CustodyTransferType::OwnerToGamePek,
            self::CustomerDelivery => CustodyTransferType::GamePekToCustomer,
            self::CustomerReturn => CustodyTransferType::CustomerToGamePek,
        };
    }
}
