<?php

namespace App\Enums;

/**
 * Which handover a custody record describes.
 *
 * All four physical legs of the confirmed lifecycle are executable (see
 * docs/operations/OPERATIONS_AND_CUSTODY.md §13 and §14):
 *
 *   owner_to_gamepek     GamePek collects a third-party owner's console
 *   gamepek_to_customer  GamePek hands the device to the customer at their
 *                        door -- the ONLY event that starts a rental
 *   customer_to_gamepek  the customer returns the device to GamePek
 *   gamepek_to_owner     GamePek hands a returned console back to its owner
 *
 * There is deliberately NO customer_to_owner leg. A customer never hands a
 * device straight to its owner: the device always passes back through GamePek,
 * which is what makes the return inspection and the owner's defect window
 * meaningful. The CHECK constraint refuses any other type outright.
 */
enum CustodyTransferType: string
{
    case OwnerToGamePek = 'owner_to_gamepek';

    case GamePekToCustomer = 'gamepek_to_customer';

    case CustomerToGamePek = 'customer_to_gamepek';

    case GamePekToOwner = 'gamepek_to_owner';

    public function label(): string
    {
        return match ($this) {
            self::OwnerToGamePek => 'از مالک به گیم‌پک',
            self::GamePekToCustomer => 'از گیم‌پک به مشتری',
            self::CustomerToGamePek => 'از مشتری به گیم‌پک',
            self::GamePekToOwner => 'از گیم‌پک به مالک',
        };
    }

    public function source(): CustodyActor
    {
        return match ($this) {
            self::OwnerToGamePek => CustodyActor::Owner,
            self::GamePekToCustomer => CustodyActor::GamePek,
            self::CustomerToGamePek => CustodyActor::Customer,
            self::GamePekToOwner => CustodyActor::GamePek,
        };
    }

    public function destination(): CustodyActor
    {
        return match ($this) {
            self::OwnerToGamePek => CustodyActor::GamePek,
            self::GamePekToCustomer => CustodyActor::Customer,
            self::CustomerToGamePek => CustodyActor::GamePek,
            self::GamePekToOwner => CustodyActor::Owner,
        };
    }

    /**
     * Does this leg end with GamePek holding the device?
     */
    public function endsInGamePekCustody(): bool
    {
        return $this->destination() === CustodyActor::GamePek;
    }

    /**
     * Does completing this leg open the owner's two-hour defect-report window?
     *
     * CONFIRMED RULE (C-38): the window runs from the moment GamePek receives
     * the device back from the customer. Only the return leg starts it. The
     * owner pickup also ends in GamePek custody, but that is the owner handing
     * over their own device before the rental -- not the receipt the rule is
     * about -- so it starts no window.
     */
    public function startsOwnerDefectReportWindow(): bool
    {
        return $this === self::CustomerToGamePek;
    }

    /** Which side of this leg is the owner, if either. */
    public function involvesOwner(): bool
    {
        return $this->source() === CustodyActor::Owner || $this->destination() === CustodyActor::Owner;
    }

    public function involvesCustomer(): bool
    {
        return $this->source() === CustodyActor::Customer || $this->destination() === CustodyActor::Customer;
    }
}
