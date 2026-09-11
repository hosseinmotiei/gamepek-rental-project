<?php

namespace App\Enums;

/**
 * Which handover a custody record describes.
 *
 * Three legs are executable: the owner pickup that has always existed, and the
 * delivery/return pair added once the business confirmed how a rental starts
 * and ends (see docs/operations/OPERATIONS_AND_CUSTODY.md §13):
 *
 *   owner_to_gamepek     GamePek collects a third-party owner's console
 *   gamepek_to_customer  GamePek hands the device to the customer at their
 *                        door -- the ONLY event that starts a rental
 *   customer_to_gamepek  the customer returns the device to GamePek
 *
 * `gamepek_to_owner` -- returning a console to its owner after a rental ends --
 * is confirmed to exist as a business step but has no service behind it yet, so
 * it stays ABSENT rather than declared-and-disabled, for the same reason
 * RentalOperationType states: an enum case that cannot be produced is a promise
 * the code does not keep.
 */
enum CustodyTransferType: string
{
    case OwnerToGamePek = 'owner_to_gamepek';

    case GamePekToCustomer = 'gamepek_to_customer';

    case CustomerToGamePek = 'customer_to_gamepek';

    public function label(): string
    {
        return match ($this) {
            self::OwnerToGamePek => 'از مالک به گیم‌پک',
            self::GamePekToCustomer => 'از گیم‌پک به مشتری',
            self::CustomerToGamePek => 'از مشتری به گیم‌پک',
        };
    }

    public function source(): CustodyActor
    {
        return match ($this) {
            self::OwnerToGamePek => CustodyActor::Owner,
            self::GamePekToCustomer => CustodyActor::GamePek,
            self::CustomerToGamePek => CustodyActor::Customer,
        };
    }

    public function destination(): CustodyActor
    {
        return match ($this) {
            self::OwnerToGamePek => CustodyActor::GamePek,
            self::GamePekToCustomer => CustodyActor::Customer,
            self::CustomerToGamePek => CustodyActor::GamePek,
        };
    }

    /**
     * Does this leg end with GamePek holding the device?
     *
     * The owner's two-hour defect-report window is measured from the moment
     * GamePek takes receipt (confirmed rule; see
     * DeviceCustodyTransfer::ownerDefectReportDeadline()), so the legs that
     * can start that clock are named here rather than re-derived by callers.
     */
    public function endsInGamePekCustody(): bool
    {
        return $this->destination() === CustodyActor::GamePek;
    }
}
