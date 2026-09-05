<?php

namespace App\Models;

use App\Enums\BankAccountState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'full_name', 'mobile', 'email', 'password',
        'avatar', 'status', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function defaultAddress()
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rental verification chain.
    //
    // Model::shouldBeStrict() is on outside production, which enables
    // preventLazyLoading -- every one of these must be eager-loaded before a
    // Blade template touches it (`$user->loadMissing('identity')`).
    // ──────────────────────────────────────────────────────────────────────

    public function identity()
    {
        return $this->hasOne(UserIdentity::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(BankAccount::class);
    }

    public function verifiedBankAccounts()
    {
        return $this->hasMany(BankAccount::class)->where('state', BankAccountState::Verified->value);
    }

    public function rentalApplications()
    {
        return $this->hasMany(RentalApplication::class);
    }

    public function verificationMedia()
    {
        return $this->hasMany(VerificationMedia::class);
    }

    public function consents()
    {
        return $this->hasMany(Consent::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
