<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

/**
 * Owner isolation for physical devices.
 *
 * Every rule here is a server-side check. Hiding a button is not authorization
 * -- the audit found routes reachable by direct POST, and these must not be.
 *
 * Note that AppServiceProvider registers a Gate::before granting super_admin
 * and admin every ability, which is why admin screens run their own explicit
 * permission checks and audit rather than leaning on this returning true. An
 * ADMIN passing these checks is intended (operational visibility); an OWNER
 * reaching another owner's device is not, and that is what these deny.
 */
class DevicePolicy
{
    public function view(User $user, Device $device): bool
    {
        return $this->ownsIt($user, $device);
    }

    public function update(User $user, Device $device): bool
    {
        $owner = $user->owner;

        return $this->ownsIt($user, $device)
            && $owner !== null
            && $owner->canManageDevices();
    }

    /**
     * Disabling is an owner action. A penalty is understood to apply but is
     * UNDEFINED, so nothing is charged here -- see DeviceRegistrationService.
     */
    public function disable(User $user, Device $device): bool
    {
        return $this->update($user, $device) && $device->isApproved();
    }

    /**
     * Nobody reaches approval through this policy.
     *
     * An owner must never approve their own device, and an admin approves
     * through the admin routes with their own permission check and audit trail
     * -- not by satisfying an owner-side ability.
     */
    public function approve(User $user, Device $device): bool
    {
        return false;
    }

    /** A GamePek device has no owner, so no owner-side ability applies to it. */
    private function ownsIt(User $user, Device $device): bool
    {
        if ($device->isOwnedByGamePek()) {
            return false;
        }

        $owner = $user->owner;

        return $owner !== null && $device->owner_id === $owner->id;
    }
}
