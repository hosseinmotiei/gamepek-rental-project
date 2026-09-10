<?php

namespace App\Enums;

/**
 * The lifecycle of a third-party device owner's profile.
 *
 * Deliberately separate from the device's own state: an owner being verified
 * says nothing about whether any particular device of theirs is approved, and
 * vice versa.
 *
 * POLICY GATE: whether owner verification must COMPLETE before that owner may
 * register a device is undecided. Today registration is permitted from
 * PendingVerification, because nothing goes live on it -- every device still
 * has to pass admin review before it can be rented. Do not tighten or loosen
 * this without an owner decision.
 */
enum OwnerState: string
{
    case Draft = 'draft';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::PendingVerification => 'در انتظار احراز هویت',
            self::Verified => 'احراز هویت شده',
            self::Suspended => 'تعلیق شده',
        };
    }

    /** May an owner in this state register or manage devices? */
    public function canManageDevices(): bool
    {
        return $this !== self::Suspended;
    }
}
