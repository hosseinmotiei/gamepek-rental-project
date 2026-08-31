<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Database\Eloquent\Model;

class UserActivityLogService
{
    /**
     * Records something a customer (not an admin) did, for admins to review
     * from the customer's own record in the panel. Mirrors ActivityLogService
     * one-for-one but keyed by user_id instead of admin_id -- kept as a
     * separate table/service rather than reusing activity_logs so the two
     * feeds (staff actions vs customer behavior) never get mixed together
     * in one list.
     */
    public static function log(
        string $action,
        ?User $user = null,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = []
    ): void {
        try {
            UserActivityLog::create([
                'user_id'      => $user?->id ?? auth()->id(),
                'action'       => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id'   => $subject?->getKey(),
                'description'  => $description,
                'properties'   => empty($properties) ? null : $properties,
                'ip_address'   => request()->ip(),
                'user_agent'   => substr(request()->userAgent() ?? '', 0, 255),
            ]);
        } catch (\Throwable) {
            // Never let logging crash the main action
        }
    }
}
