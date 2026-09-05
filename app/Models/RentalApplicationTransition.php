<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only. */
class RentalApplicationTransition extends Model
{
    public $timestamps = false;

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
