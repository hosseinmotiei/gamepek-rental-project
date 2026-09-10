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
    /**
     * `state` is deliberately NOT here. It is written by
     * RentalChainOrchestrator through an explicit property assignment, so no
     * mass assignment -- and therefore no request payload, hidden field or
     * route parameter -- can ever set it.
     */
    protected $fillable = [
        'application_number', 'user_id', 'order_id', 'correlation_id',
        'submitted_at', 'approved_at', 'rejected_at', 'cancelled_at',
        'rejection_reason', 'admin_note',

        // The customer's choice, held here rather than in a reservation row.
        // C-15/C-16: no reservation exists until payment clears, so there is
        // nowhere else for it to live between selection and payment.
        // `quote` is a server-computed snapshot; it is never taken from input.
        'product_id', 'selected_start_date', 'selected_end_date',
        'selected_days', 'selected_extra_controller', 'quote',
    ];

    protected function casts(): array
    {
        return [
            'state' => RentalApplicationState::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'selected_start_date' => 'date',
            'selected_end_date' => 'date',
            'selected_days' => 'integer',
            'selected_extra_controller' => 'boolean',
            'quote' => 'array',
        ];
    }

    /**
     * Has the customer chosen a product and a date range?
     *
     * Holds nothing and blocks no inventory -- it is the answer to "is there
     * something to pay for", not "is anything reserved".
     */
    public function hasSelection(): bool
    {
        return $this->product_id !== null
            && $this->selected_start_date !== null
            && $this->selected_end_date !== null
            && $this->selected_days !== null;
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

    /** The selected product. Null until the customer chooses one. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
