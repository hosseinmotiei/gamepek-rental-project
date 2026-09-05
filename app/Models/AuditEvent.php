<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Never updated, never deleted by application code.
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_type', 'actor_id', 'actor_label',
        'action', 'resource_type', 'resource_id',
        'result', 'correlation_id', 'request_id',
        'ip_address', 'user_agent', 'context', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Only meaningful when actor_type is user|admin. There is deliberately no
     * foreign key, so this can dangle -- always use `?->` at call sites.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForCorrelation($query, string $correlationId)
    {
        return $query->where('correlation_id', $correlationId);
    }

    public function scopeForResource($query, string $type, int|string $id)
    {
        return $query->where('resource_type', $type)->where('resource_id', $id);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }
}
