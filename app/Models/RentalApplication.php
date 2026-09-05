<?php

namespace App\Models;

use App\Enums\RentalApplicationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * The chain aggregate.
 *
 * `state` is derived, never assigned outside RentalChainOrchestrator. If you
 * find yourself writing `$application->state = ...` anywhere else, that is the
 * bug -- change the child fact instead and call advance().
 */
class RentalApplication extends Model
{
    protected $fillable = [
        'application_number', 'user_id', 'order_id', 'state', 'correlation_id',
        'submitted_at', 'approved_at', 'rejected_at', 'cancelled_at',
        'rejection_reason', 'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'state' => RentalApplicationState::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Route by the human-readable number rather than the primary key: the
     * number is already unique, it is what the customer sees, and it keeps
     * sequential row ids out of shared URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'application_number';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(RentalReservation::class);
    }

    public function guarantee(): HasOne
    {
        return $this->hasOne(Guarantee::class)->latestOfMany();
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(RentalApplicationTransition::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(VerificationMedia::class, 'rental_application_id');
    }

    public static function generateNumber(): string
    {
        return 'RA-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }
}
