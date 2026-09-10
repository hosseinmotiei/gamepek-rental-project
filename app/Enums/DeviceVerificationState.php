<?php

namespace App\Enums;

/**
 * Whether a device has been VERIFIED -- a separate question from whether it has
 * been registered or approved.
 *
 * Registration means an owner submitted it. Approval means an admin allowed it
 * into the fleet. Verification means its identity and condition were actually
 * checked. Collapsing the three would let "the form saved" masquerade as "the
 * device is real", which is exactly the failure mode the verification layer
 * exists to prevent.
 *
 * The full device verification workflow (video, serial confirmation, condition
 * evidence) is a later phase. This enum is the seam it will attach to; nothing
 * here performs a check.
 */
enum DeviceVerificationState: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'بررسی نشده',
            self::Pending => 'در انتظار بررسی',
            self::Verified => 'تأیید شده',
            self::Failed => 'ناموفق',
        };
    }
}
