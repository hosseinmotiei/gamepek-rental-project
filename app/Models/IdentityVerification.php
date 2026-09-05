<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    public const TYPE_SHAHKAR = 'shahkar';

    public const TYPE_CIVIL_REGISTRY = 'civil_registry';

    public const TYPE_LIVENESS = 'liveness';

    public const TYPE_FACE_MATCH = 'face_match';

    public const STATE_CHECKING = 'checking';

    public const STATE_PASSED = 'passed';

    public const STATE_FAILED = 'failed';

    public const STATE_MANUAL_REVIEW = 'manual_review';

    protected $fillable = [
        'user_identity_id', 'type', 'provider', 'state', 'score',
        'provider_reference', 'provider_status_code',
        'request_id', 'correlation_id',
        'raw_request', 'raw_response',
        'checked_at', 'retention_until', 'duration_ms',
    ];

    /** Provider payloads may carry personal data. */
    protected $hidden = ['raw_request', 'raw_response'];

    protected function casts(): array
    {
        return [
            'raw_request' => 'array',
            'raw_response' => 'array',
            'checked_at' => 'datetime',
            'retention_until' => 'date',
            'score' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_identity_id');
    }

    public function scopePassed($query)
    {
        return $query->where('state', self::STATE_PASSED);
    }
}
