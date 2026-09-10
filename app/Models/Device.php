<?php

namespace App\Models;

use App\Enums\CustodyActor;
use App\Enums\DeviceOwnership;
use App\Enums\DeviceState;
use App\Enums\DeviceVerificationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One registered physical rental device -- and the rentable unit itself.
 *
 * See the devices migration for why there is no separate `device_units` table:
 * a row here already carries exactly one serial number, so it already is the
 * concrete physical instance.
 *
 * `state` and `approved_at`/`rejected_at`/`disabled_at` are NOT fillable.
 * Registration is an owner action; approval is an admin one, and neither a
 * request field nor a route parameter may write the review outcome. The same
 * reasoning as `RentalApplication::$state`.
 *
 * Ownership likewise is not fillable: an owner must not be able to hand their
 * device to someone else, or claim a GamePek device, by posting a field.
 */
class Device extends Model
{
    protected $fillable = [
        'product_id', 'serial_number', 'serial_normalized', 'condition', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'ownership' => DeviceOwnership::class,
            'state' => DeviceState::class,
            'verification_state' => DeviceVerificationState::class,
            'registered_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Physical identity, comparable.
     *
     * The smallest normalisation that survives real data entry: trim, drop
     * every internal space and separator, uppercase. Manufacturers print
     * serials with spaces and hyphens inconsistently, and "xk-52 991" and
     * "XK52991" are the same console. Nothing beyond that is stripped -- no
     * evidence justifies guessing at more.
     */
    public static function normalizeSerial(string $serial): string
    {
        return strtoupper(preg_replace('/[\s\-_]+/u', '', trim($serial)) ?? '');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Null for GamePek-owned stock -- by design, not by omission. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(RentalReservation::class);
    }

    public function custodyTransfers(): HasMany
    {
        return $this->hasMany(DeviceCustodyTransfer::class);
    }

    /**
     * Who physically holds this device right now.
     *
     * DERIVED, never stored. There is no `current_custody` column on purpose:
     * a stored copy and this computation are two places to disagree, and the
     * one that would silently win is the stale one.
     *
     * With no recorded handover the holder is whoever owns it -- a GamePek
     * console sitting in the GamePek warehouse needs no GamePek -> GamePek
     * transfer row to prove GamePek has it, and writing one would be recording
     * a handover that never happened.
     *
     * `requested` transfers are ignored: asking for a device is not holding it.
     */
    public function currentCustody(): CustodyActor
    {
        $latest = $this->relationLoaded('custodyTransfers')
            ? $this->custodyTransfers->filter->isPossessionMoved()->sortByDesc('id')->first()
            : $this->custodyTransfers()->possessionMoved()->latest('id')->first();

        return $latest?->to_actor_type
            ?? ($this->isOwnedByGamePek() ? CustodyActor::GamePek : CustodyActor::Owner);
    }

    public function isInGamePekCustody(): bool
    {
        return $this->currentCustody() === CustodyActor::GamePek;
    }

    public function isOwnedByGamePek(): bool
    {
        return $this->ownership === DeviceOwnership::GamePek;
    }

    public function isApproved(): bool
    {
        return $this->state === DeviceState::Approved;
    }

    /** Part of the rentable fleet? Only approved devices are. */
    public function isRentable(): bool
    {
        return $this->state->isRentable();
    }

    public function ownerLabel(): string
    {
        return $this->isOwnedByGamePek()
            ? 'گیم‌پک'
            : ($this->owner?->displayName() ?? 'نامشخص');
    }

    public function maskedSerial(): string
    {
        $serial = $this->serial_number ?? '';

        if (mb_strlen($serial) <= 4) {
            return str_repeat('*', mb_strlen($serial));
        }

        return mb_substr($serial, 0, 2).str_repeat('*', max(0, mb_strlen($serial) - 4)).mb_substr($serial, -2);
    }

    public function scopeForOwner($query, int $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeGamePekOwned($query)
    {
        return $query->where('ownership', DeviceOwnership::GamePek->value);
    }

    public function scopeRentable($query)
    {
        return $query->where('state', DeviceState::Approved->value);
    }
}
