<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'version', 'text_key',
        'granted_at', 'revoked_at', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->granted_at !== null && $this->revoked_at === null;
    }
}
