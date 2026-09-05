<?php

namespace App\Policies;

use App\Models\RentalApplication;
use App\Models\User;

/**
 * Ownership is mandatory for every user-specific resource
 * (.claude/rules/backend-services.md). Staff access goes through the admin
 * routes and their own permissions, not through this policy -- note that
 * AppServiceProvider registers a Gate::before granting super_admin/admin
 * everything, which is exactly why admin screens must ALSO be permission
 * checked and audited rather than relying on this returning true.
 */
class RentalApplicationPolicy
{
    public function view(User $user, RentalApplication $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function update(User $user, RentalApplication $application): bool
    {
        return $application->user_id === $user->id
            && ! $application->state->isTerminal();
    }
}
