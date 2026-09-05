<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogService
{
    public static function log(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = []
    ): void {
        try {
            ActivityLog::create([
                'admin_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'description' => $description,
                'properties' => empty($properties) ? null : $properties,
                'ip_address' => request()->ip(),
                'user_agent' => substr(request()->userAgent() ?? '', 0, 255),
            ]);
        } catch (\Throwable) {
            // Never let logging crash the main action
        }
    }
}
