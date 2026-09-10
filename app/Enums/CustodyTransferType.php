<?php

namespace App\Enums;

/**
 * Which handover a custody record describes.
 *
 * Only OwnerToGamePek exists. The delivery, customer-return and owner-return
 * legs belong to later phases and have no service behind them, so they are not
 * declared here -- see RentalOperationType for the same reasoning.
 */
enum CustodyTransferType: string
{
    case OwnerToGamePek = 'owner_to_gamepek';

    public function label(): string
    {
        return match ($this) {
            self::OwnerToGamePek => 'از مالک به گیم‌پک',
        };
    }

    public function source(): CustodyActor
    {
        return match ($this) {
            self::OwnerToGamePek => CustodyActor::Owner,
        };
    }

    public function destination(): CustodyActor
    {
        return match ($this) {
            self::OwnerToGamePek => CustodyActor::GamePek,
        };
    }
}
