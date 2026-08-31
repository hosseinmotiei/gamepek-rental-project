<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use MassPrunable;

    protected $fillable = [
        'mobile', 'code', 'purpose', 'is_used', 'attempts', 'expires_at',
    ];

    /**
     * Rows hold a keyed hash of a delivered OTP plus the mobile number it was
     * sent to, so they are retained only as long as verification needs them.
     * The daily `model:prune` in routes/console.php is a no-op without this.
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }

    protected function casts(): array
    {
        return [
            'is_used' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return !$this->is_used && !$this->isExpired();
    }

    public function scopeForMobile($query, string $mobile)
    {
        return $query->where('mobile', $mobile);
    }

    public function scopeValid($query)
    {
        return $query->where('is_used', false)->where('expires_at', '>', now());
    }
}
