<?php

namespace App\Models;

use App\Enums\GuaranteeState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guarantee extends Model
{
    public const TYPE_CHEQUE = 'cheque';

    public const TYPE_PROMISSORY_NOTE = 'promissory_note';

    protected $fillable = [
        'rental_application_id', 'type', 'sayad_id', 'amount', 'due_date',
        'bank_code', 'bank_name', 'state', 'ownership_match', 'risk_score',
        'verified_at', 'released_at', 'rejected_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'verified_at' => 'datetime',
            'released_at' => 'datetime',
            'rejected_at' => 'datetime',
            'amount' => 'integer',
            'ownership_match' => 'boolean',
            'risk_score' => 'integer',
            'state' => GuaranteeState::class,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class, 'rental_application_id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(GuaranteeInquiry::class);
    }

    public function isVerified(): bool
    {
        return $this->state === GuaranteeState::Verified;
    }
}
