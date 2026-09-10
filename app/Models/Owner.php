<?php

namespace App\Models;

use App\Enums\OwnerState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A third-party device owner.
 *
 * Having this row IS the owner capability -- there is no parallel Spatie role,
 * deliberately, so the two cannot drift. OwnerPolicy asks for the profile.
 *
 * GamePek's own devices have no Owner row; `devices.ownership` says so
 * explicitly. See the devices migration.
 */
class Owner extends Model
{
    protected $fillable = ['user_id', 'display_name'];

    protected function casts(): array
    {
        return [
            'state' => OwnerState::class,
            'verified_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** What operational screens call this owner. */
    public function displayName(): string
    {
        return $this->display_name ?: ($this->user?->full_name ?? 'مالک');
    }

    public function isVerified(): bool
    {
        return $this->state === OwnerState::Verified;
    }

    public function isSuspended(): bool
    {
        return $this->state === OwnerState::Suspended;
    }

    /**
     * May this owner register or manage devices?
     *
     * POLICY GATE: this deliberately does NOT require verification. Nothing
     * goes live on an unverified owner -- every device still needs admin
     * approval before it can be rented. Whether owner verification must
     * complete first is an owner decision.
     */
    public function canManageDevices(): bool
    {
        return $this->state->canManageDevices();
    }

    public function scopeVerified($query)
    {
        return $query->where('state', OwnerState::Verified->value);
    }
}
