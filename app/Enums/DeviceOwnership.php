<?php

namespace App\Enums;

/**
 * Where a physical device comes from.
 *
 * The fleet is mixed: GamePek owns devices AND third-party owners supply them.
 * A GamePek device must NOT need a fake owner account to exist, so ownership is
 * an explicit column rather than "owner_id is null means us".
 *
 * The invariant, enforced by a CHECK constraint on `devices`:
 *
 *   Owner   => owner_id IS NOT NULL
 *   GamePek => owner_id IS NULL
 *
 * Every physical device therefore has exactly one unambiguous ownership source.
 */
enum DeviceOwnership: string
{
    case GamePek = 'gamepek';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::GamePek => 'مالکیت گیم‌پک',
            self::Owner => 'مالک شخص ثالث',
        };
    }

    public function requiresOwner(): bool
    {
        return $this === self::Owner;
    }
}
