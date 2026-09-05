<?php

namespace App\Models;

use App\Enums\IdentityState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * KYC level-2 identity. See the create_user_identities_table migration for why
 * this is a satellite of `users` rather than columns on it.
 *
 * The raw national code is write-only from the outside: set it through
 * setNationalCode(), read the mask. nationalCode() decrypts and exists for the
 * provider adapters alone -- never call it from a Blade template.
 */
class UserIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'national_code_encrypted', 'national_code_hash', 'national_code_mask',
        'birth_date',
        'registry_first_name', 'registry_last_name', 'registry_father_name',
        'kyc_level', 'state',
        'verified_at', 'rejected_at', 'rejection_reason',
    ];

    /** The encrypted blob must never leak through a toArray()/toJson(). */
    protected $hidden = ['national_code_encrypted', 'national_code_hash'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'kyc_level' => 'integer',
            'state' => IdentityState::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class);
    }

    public function setNationalCode(string $nationalCode): void
    {
        $digits = preg_replace('/\D/', '', $nationalCode);

        $this->national_code_encrypted = Crypt::encryptString($digits);
        $this->national_code_hash = self::hashNationalCode($digits);
        $this->national_code_mask = self::maskNationalCode($digits);
    }

    /** Decrypts. For provider adapters only -- never for a view. */
    public function nationalCode(): ?string
    {
        if (! $this->national_code_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->national_code_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * HMAC, not a bare hash: a plain sha256 of a 10-digit national code is
     * trivially reversible by brute force over the whole 10^10 keyspace.
     * Keyed with APP_KEY, so the digest is useless without it.
     */
    public static function hashNationalCode(string $nationalCode): string
    {
        return hash_hmac('sha256', $nationalCode, config('app.key'));
    }

    public static function maskNationalCode(string $nationalCode): string
    {
        if (strlen($nationalCode) < 4) {
            return str_repeat('*', strlen($nationalCode));
        }

        return substr($nationalCode, 0, 2).str_repeat('*', strlen($nationalCode) - 4).substr($nationalCode, -2);
    }

    /** Safe for Blade. */
    public function getNationalCodeMaskedAttribute(): ?string
    {
        return $this->national_code_mask;
    }

    public function isVerified(): bool
    {
        return $this->state === IdentityState::Verified;
    }
}
