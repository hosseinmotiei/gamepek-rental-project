<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractSignature extends Model
{
    protected $fillable = [
        'contract_id', 'user_id', 'method',
        'signed_content_hash', 'signature', 'otp_reference',
        'ip_address', 'user_agent', 'evidence', 'signed_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'signed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
