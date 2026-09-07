<?php

namespace App\Models;

use App\Enums\GuaranteeState;
use App\Support\Guarantee\SayadId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Guarantee extends Model
{
    public const TYPE_CHEQUE = 'cheque';

    public const TYPE_PROMISSORY_NOTE = 'promissory_note';

    protected $fillable = [
        'rental_application_id', 'type', 'amount', 'due_date',
        'bank_code', 'bank_name', 'state', 'ownership_match', 'risk_score',
        'verified_at', 'released_at', 'rejected_at', 'rejection_reason',
    ];

    /**
     * The Sayad id is never mass assignable and never serialised: it is
     * written through setSayadId() and read back only by the provider
     * adapters, exactly as UserIdentity treats the national code.
     */
    protected $hidden = ['sayad_id_encrypted', 'sayad_id_hash'];

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

    /** Encrypts, hashes and masks in one write. */
    public function setSayadId(?string $sayadId): void
    {
        $digits = SayadId::normalise((string) $sayadId);

        if ($digits === '') {
            $this->sayad_id_encrypted = null;
            $this->sayad_id_hash = null;
            $this->sayad_id_mask = null;

            return;
        }

        $this->sayad_id_encrypted = Crypt::encryptString($digits);
        $this->sayad_id_hash = self::hashSayadId($digits);
        $this->sayad_id_mask = SayadId::mask($digits);
    }

    /** For provider adapters only -- never for a view or an audit context. */
    public function sayadId(): ?string
    {
        if (! $this->sayad_id_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->sayad_id_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Keyed with APP_KEY for the same reason as
     * UserIdentity::hashNationalCode(): a bare hash of a 16-digit number is
     * brute-forceable over its whole keyspace.
     */
    public static function hashSayadId(string $sayadId): string
    {
        return hash_hmac('sha256', SayadId::normalise($sayadId), config('app.key'));
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
