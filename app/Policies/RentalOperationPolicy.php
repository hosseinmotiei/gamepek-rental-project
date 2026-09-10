<?php

namespace App\Policies;

use App\Models\RentalOperation;
use App\Models\User;

/**
 * Owner isolation for operational tasks and the custody records under them.
 *
 * An owner may watch their own pickup and confirm that GamePek's record of the
 * handover matches what they did. They may not drive the task: scheduling,
 * starting, recording receipt and marking failure are GamePek's operational
 * decisions and live behind admin permissions.
 *
 * As with DevicePolicy, note that AppServiceProvider registers a Gate::before
 * granting super_admin and admin every ability. That is why the admin screens
 * check their own permissions explicitly instead of leaning on this returning
 * true -- an ADMIN seeing an operation is intended, an OWNER reaching another
 * owner's operation is not, and that is what these deny.
 */
class RentalOperationPolicy
{
    public function view(User $user, RentalOperation $operation): bool
    {
        return $this->ownsIt($user, $operation);
    }

    /**
     * Confirm GamePek's record of the handover.
     *
     * Not a signature and not legal acceptance -- see CustodyTransferState.
     */
    public function acknowledgeCustody(User $user, RentalOperation $operation): bool
    {
        return $this->ownsIt($user, $operation);
    }

    /**
     * Nobody reaches lifecycle changes through this policy.
     *
     * An owner must not be able to schedule, start, complete or fail a task,
     * or to name GamePek as having received a device it has not received.
     * Those go through the admin routes with their own permission check and
     * audit trail.
     */
    public function manage(User $user, RentalOperation $operation): bool
    {
        return false;
    }

    /**
     * An operation belongs to an owner only once a device of theirs is on it.
     *
     * Before allocation there is no owner, so no owner-side ability applies --
     * which is also why a task awaiting allocation appears in nobody's owner
     * panel. A GamePek-owned device has no owner at all.
     */
    private function ownsIt(User $user, RentalOperation $operation): bool
    {
        if ($operation->owner_id === null) {
            return false;
        }

        $owner = $user->owner;

        return $owner !== null && $operation->owner_id === $owner->id;
    }
}
