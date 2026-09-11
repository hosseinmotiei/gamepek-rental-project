<?php

namespace App\Models;

use App\Enums\RentalInspectionStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of condition evidence about a device, recorded against the
 * delivery or return it was observed at.
 *
 * APPEND-ONLY. Written by RentalInspectionService alone; once saved it can be
 * neither changed nor deleted. A correction is a new row, so the evidence
 * trail keeps what was originally written and by whom -- the property a
 * dispute handled through GamePek's evidence (C-38) depends on.
 *
 * NOTHING is mass-assignable, for the same reason as DeviceCustodyTransfer: no
 * request field may name the device, the rental, the stage or the inspector.
 *
 * STAFF-ONLY. Findings are internal evidence and never rendered on a customer
 * or owner screen.
 */
class RentalInspection extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'stage' => RentalInspectionStage::class,
            'inspected_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Rental inspections are append-only and cannot be modified.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Rental inspections are append-only and cannot be deleted.');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(RentalOperation::class, 'rental_operation_id');
    }

    public function custodyTransfer(): BelongsTo
    {
        return $this->belongsTo(DeviceCustodyTransfer::class, 'device_custody_transfer_id');
    }

    public function damageAssessments(): HasMany
    {
        return $this->hasMany(RentalDamageAssessment::class, 'rental_inspection_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }
}
