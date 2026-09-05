<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VerificationMedia;

class VerificationMediaPolicy
{
    public function view(User $user, VerificationMedia $media): bool
    {
        return $media->user_id === $user->id;
    }
}
