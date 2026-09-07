<?php

namespace App\Models;

use App\Enums\ContractState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Contract extends Model
{
    protected $fillable = [
        'rental_application_id', 'contract_template_id',
        'template_key', 'template_version', 'number',
        'variables', 'rendered_html', 'content_hash', 'state',
        'generated_at', 'accepted_at', 'accepted_by_user_id', 'accepted_ip', 'accepted_user_agent',
        'signed_at', 'voided_at', 'storage_disk', 'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'template_version' => 'integer',
            'generated_at' => 'datetime',
            'accepted_at' => 'datetime',
            'signed_at' => 'datetime',
            'voided_at' => 'datetime',
            'state' => ContractState::class,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class, 'rental_application_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class);
    }

    /** Recomputed from the stored text -- differs from content_hash iff tampered. */
    public function currentHash(): string
    {
        return hash('sha256', (string) $this->rendered_html);
    }

    public function isIntact(): bool
    {
        return hash_equals((string) $this->content_hash, $this->currentHash());
    }

    public static function generateNumber(): string
    {
        return 'CNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }
}
