<?php

namespace App\Enums;

/**
 * The kind of physical work an operational task represents.
 *
 * SCOPE: four types, one per physical custody leg of the confirmed lifecycle
 * (docs/operations/OPERATIONS_AND_CUSTODY.md §13 and §14):
 *
 *   owner_device_pickup  owner   -> GamePek
 *   customer_delivery    GamePek -> customer   (Approved -> Active)
 *   customer_return      customer -> GamePek   (Active -> Returned)
 *   owner_return         GamePek -> owner      (no application-state effect)
 *
 * Inspection is NOT an operation type. It is evidence recorded against a
 * delivery or a return (App\Models\RentalInspection), not a separate piece of
 * physical work with its own custody leg.
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

    /**
     * GamePek hands a returned console back to its third-party owner (C-40).
     * Changes no application state: closure remains undecided.
     */
    case OwnerReturn = 'owner_return';

    public function label(): string
    {
        return match ($this) {
            self::OwnerDevicePickup => 'تحویل گرفتن دستگاه از مالک',
            self::CustomerDelivery => 'تحویل دستگاه به مشتری',
            self::CustomerReturn => 'بازگشت دستگاه از مشتری',
            self::OwnerReturn => 'بازگرداندن دستگاه به مالک',
        };
    }

    /** The custody leg this task records when it completes. */
    public function custodyTransferType(): CustodyTransferType
    {
        return match ($this) {
            self::OwnerDevicePickup => CustodyTransferType::OwnerToGamePek,
            self::CustomerDelivery => CustodyTransferType::GamePekToCustomer,
            self::CustomerReturn => CustodyTransferType::CustomerToGamePek,
            self::OwnerReturn => CustodyTransferType::GamePekToOwner,
        };
    }

    /**
     * The inspection stage this task's handover is inspected under, or null
     * when no inspection is confirmed for it.
     *
     * Only the two handovers the confirmed rules attach a condition check to
     * are inspectable: the door check on delivery (C-34) and the check when
     * the customer's device comes back (C-37/C-39). No rule attaches one to
     * the owner legs, so none is invented.
     */
    public function inspectionStage(): ?RentalInspectionStage
    {
        return match ($this) {
            self::CustomerDelivery => RentalInspectionStage::Delivery,
            self::CustomerReturn => RentalInspectionStage::CustomerReturn,
            default => null,
        };
    }
}
