<?php

namespace App\Policies;

use App\Models\Owner;
use App\Models\User;

/**
 * An owner profile belongs to exactly one account and is visible only to it.
 *
 * Owner verification data is personal data; cross-owner access is denied here
 * rather than relying on route-model binding, which the audit found insufficient
 * elsewhere in this codebase.
 */
class OwnerPolicy
{
    public function view(User $user, Owner $owner): bool
    {
        return $owner->user_id === $user->id;
    }

    public function manageDevices(User $user, Owner $owner): bool
    {
        return $owner->user_id === $user->id && $owner->canManageDevices();
    }
}
