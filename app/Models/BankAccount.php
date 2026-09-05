<?php

namespace App\Models;

use App\Enums\BankAccountState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class BankAccount extends Model
{
    public const TYPE_CARD = 'card';

    public const TYPE_IBAN = 'iban';

    protected $fillable = [
        'user_id', 'type', 'value_encrypted', 'value_hash', 'value_mask',
        'owner_name', 'bank_name', 'linked_iban_mask',
        'state', 'provider', 'provider_reference', 'verified_at', 'failure_reason',
    ];

    protected $hidden = ['value_encrypted', 'value_hash'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'state' => BankAccountState::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setValue(string $value): void
    {
        $normalised = self::normalise($this->type, $value);

        $this->value_encrypted = Crypt::encryptString($normalised);
        $this->value_hash = self::hashValue($normalised);
        $this->value_mask = self::mask($this->type, $normalised);
    }

    /** Decrypts. For provider adapters only. */
    public function value(): ?string
    {
        if (! $this->value_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->value_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function normalise(string $type, string $value): string
    {
        $value = strtoupper(preg_replace('/[\s-]/', '', $value));

        if ($type === self::TYPE_IBAN) {
            return str_starts_with($value, 'IR') ? $value : 'IR'.preg_replace('/\D/', '', $value);
        }

        return preg_replace('/\D/', '', $value);
    }

    /** Keyed, for the same reason as UserIdentity::hashNationalCode(). */
    public static function hashValue(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    public static function mask(string $type, string $value): string
    {
        if ($type === self::TYPE_CARD && strlen($value) === 16) {
            return substr($value, 0, 6).'******'.substr($value, -4);
        }

        if (strlen($value) <= 8) {
            return $value;
        }

        return substr($value, 0, 6).str_repeat('*', max(0, strlen($value) - 10)).substr($value, -4);
    }

    public function isVerified(): bool
    {
        return $this->state === BankAccountState::Verified;
    }
}
