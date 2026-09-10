<?php

namespace App\Models;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\CustodyTransferType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded handover of physical possession.
 *
 * This model never writes to `devices`. Ownership is not its business and it
 * has no method that could change it -- see DeviceCustodyService, which asserts
 * the same thing at the service boundary.
 *
 * Nothing here is fillable. Actors, type, state and timestamps are all written
 * by DeviceCustodyService; a request must not be able to name itself as the
 * source or destination of a custody transfer.
 */
class DeviceCustodyTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_actor_type' => CustodyActor::class,
            'to_actor_type' => CustodyActor::class,
            'transfer_type' => CustodyTransferType::class,
            'state' => CustodyTransferState::class,
            'initiated_at' => 'datetime',
            'transferred_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(RentalOperation::class, 'rental_operation_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(RentalReservation::class, 'rental_reservation_id');
    }

    public function fromOwner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'from_owner_id');
    }

    public function toOwner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'to_owner_id');
    }

    /** Has possession actually moved? `requested` means it has not. */
    public function isPossessionMoved(): bool
    {
        return $this->state->isPossessionMoved();
    }

    /** Only meaningful once possession has moved. */
    public function holder(): ?CustodyActor
    {
        return $this->isPossessionMoved() ? $this->to_actor_type : null;
    }

    public function scopePossessionMoved($query)
    {
        return $query->whereIn('state', [
            CustodyTransferState::Transferred->value,
            CustodyTransferState::Acknowledged->value,
        ]);
    }
}
