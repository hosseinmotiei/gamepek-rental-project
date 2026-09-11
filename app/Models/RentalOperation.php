<?php

namespace App\Models;

use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One unit of physical work attached to a paid reservation.
 *
 * `state`, `type`, `device_id`, `owner_id` and every lifecycle timestamp are
 * NOT fillable. Lifecycle is written by RentalOperationService alone -- the
 * same single-writer discipline RentalChainOrchestrator applies to the
 * application chain and DeviceRegistrationService to the fleet. An operator
 * must not be able to mark a pickup complete, or point it at someone else's
 * device, by posting a field.
 *
 * The only fillable columns are the ones a form may legitimately supply:
 * scheduling notes and the assigned staff member.
 */
class RentalOperation extends Model
{
    protected $fillable = ['assigned_to_user_id', 'notes'];

    protected function casts(): array
    {
        return [
            'type' => RentalOperationType::class,
            'state' => RentalOperationState::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** OPS-YYMMDD-XXXXXX. Quotable on the phone, unique in the database. */
    public static function generateNumber(): string
    {
        return 'OPS-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(RentalReservation::class, 'rental_reservation_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class, 'rental_application_id');
    }

    /** NULL until a human attaches one. Never auto-filled. */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** At most one handover per task -- enforced by a unique index. */
    public function custodyTransfer(): HasOne
    {
        return $this->hasOne(DeviceCustodyTransfer::class, 'rental_operation_id');
    }

    /** Condition evidence recorded against this task's handover. Append-only. */
    public function inspections(): HasMany
    {
        return $this->hasMany(RentalInspection::class, 'rental_operation_id');
    }

    public function isTerminal(): bool
    {
        return $this->state->isTerminal();
    }

    public function hasDevice(): bool
    {
        return $this->device_id !== null;
    }

    /** Safe for logs and screens: never the raw serial. */
    public function deviceLabel(): string
    {
        return $this->device?->maskedSerial() ?? 'تخصیص نیافته';
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('state', [
            RentalOperationState::Completed->value,
            RentalOperationState::NotRequired->value,
        ]);
    }

    public function scopeForOwner($query, int $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }
}
