<?php

namespace App\Models;

use App\Services\Notification\RentalLifecycleNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only. */
class RentalApplicationTransition extends Model
{
    public $timestamps = false;

    /**
     * Every committed state change is exactly one row here, which makes this
     * the right hook for lifecycle notifications: RentalChainOrchestrator stays
     * the sole writer of state and knows nothing about SMS. The notifier defers
     * to after the commit and can never throw back into it.
     */
    protected static function booted(): void
    {
        static::created(fn (self $transition) => app(RentalLifecycleNotifier::class)->transitionRecorded($transition));
    }

    protected $fillable = [
        'rental_application_id', 'from_state', 'to_state', 'reason',
        'actor_type', 'actor_id', 'correlation_id', 'context', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class, 'rental_application_id');
    }
}
