<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GamePek expert's damage amount for a returned device (C-39).
 *
 * APPEND-ONLY and staff-only. Written by RentalDamageAssessmentService alone;
 * nothing is mass-assignable. Recording one charges nobody.
 */
class RentalDamageAssessment extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'assessed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Damage assessments are append-only and cannot be modified.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Damage assessments are append-only and cannot be deleted.');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }
}
