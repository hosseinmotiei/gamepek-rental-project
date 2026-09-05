<?php

namespace App\Models;

use App\Enums\MediaState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationMedia extends Model
{
    protected $table = 'verification_media';

    protected $fillable = [
        'user_id', 'rental_application_id', 'kind',
        'disk', 'path', 'original_name', 'mime', 'size_bytes',
        'duration_seconds', 'checksum', 'metadata',
        'state', 'rejection_reason', 'retention_until', 'purged_at',
    ];

    /** `path` is not a secret, but exposing it invites direct-URL guessing. */
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'retention_until' => 'date',
            'purged_at' => 'datetime',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'state' => MediaState::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class, 'rental_application_id');
    }

    public function isPurged(): bool
    {
        return $this->state === MediaState::Purged;
    }
}
