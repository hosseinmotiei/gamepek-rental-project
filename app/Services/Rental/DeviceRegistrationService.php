<?php

namespace App\Services\Rental;

use App\Enums\DeviceOwnership;
use App\Enums\DeviceState;
use App\Enums\DeviceVerificationState;
use App\Models\Device;
use App\Models\Owner;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registering physical devices and moving them through admin review.
 *
 * The only writer of `devices.state`, `devices.ownership` and `devices.owner_id`
 * -- the same single-writer discipline RentalChainOrchestrator applies to the
 * application chain, for the same reason: review outcome and ownership must not
 * be settable from a request.
 *
 * What this deliberately does NOT do:
 *
 *  - It does not verify anything. Registration puts a device in PendingReview
 *    with verification_state Unverified. A saved form is not a checked device;
 *    the real verification workflow attaches to that seam in a later phase.
 *  - It does not allocate devices to reservations. Which free device a paid
 *    reservation gets is an undecided policy (see the reservations migration).
 *  - It charges nothing when a device is disabled. A penalty is understood to
 *    exist but its amount is UNDEFINED.
 */
class DeviceRegistrationService
{
    /**
     * Turn an authenticated user into a device owner.
     *
     * Idempotent: an existing profile is returned untouched, so this can back a
     * plain "become an owner" action without a duplicate check at the call site.
     */
    public function ensureOwnerProfile(User $user, ?string $displayName = null): Owner
    {
        $existing = Owner::where('user_id', $user->id)->first();

        if ($existing) {
            return $existing;
        }

        $owner = Owner::create([
            'user_id' => $user->id,
            'display_name' => $displayName,
        ]);

        // `state` is not fillable; the column default lands in the database.
        $owner->refresh();

        AuditLogger::log(
            action: 'owner.registered',
            resourceType: 'Owner',
            resourceId: $owner->id,
            context: ['user_id' => $user->id, 'state' => $owner->state->value],
            actor: $user,
        );

        return $owner;
    }

    /**
     * Register a third-party owner's physical device.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function registerForOwner(Owner $owner, Product $product, string $serial, array $attributes = []): Device
    {
        if (! $owner->canManageDevices()) {
            throw new \RuntimeException('حساب مالک شما در حال حاضر امکان ثبت دستگاه ندارد.');
        }

        return $this->create(
            product: $product,
            serial: $serial,
            ownership: DeviceOwnership::Owner,
            owner: $owner,
            attributes: $attributes,
            actor: $owner->user,
        );
    }

    /**
     * Register a device GamePek itself owns.
     *
     * No Owner row is created: first-party stock is expressed by
     * `ownership = gamepek` with a null owner_id, so GamePek never needs a fake
     * customer account to hold its own consoles.
     */
    public function registerForGamePek(Product $product, string $serial, array $attributes = [], ?User $actor = null): Device
    {
        return $this->create(
            product: $product,
            serial: $serial,
            ownership: DeviceOwnership::GamePek,
            owner: null,
            attributes: $attributes,
            actor: $actor,
        );
    }

    /** Admin decision. Never derived, never self-service. */
    public function approve(Device $device, User $admin, ?string $note = null): Device
    {
        return $this->transition($device, DeviceState::Approved, $admin, [
            'approved_at' => now(),
            'approved_by_user_id' => $admin->id,
            'rejection_reason' => null,
        ], $note);
    }

    public function reject(Device $device, User $admin, string $reason): Device
    {
        return $this->transition($device, DeviceState::Rejected, $admin, [
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ], $reason);
    }

    /**
     * Take a device out of the fleet.
     *
     * POLICY GATE: an owner may do this and a penalty is understood to apply.
     * The amount, the formula, and any suspension or reputation consequence are
     * UNDEFINED, so nothing is charged, deducted or escalated -- the act is
     * recorded and audited, and that is all.
     */
    /**
     * CONFIRMED: an owner may not take a device back before its rental ends.
     * Disabling is the only way a device leaves the fleet, so it is refused
     * while the device is committed to a live rental -- checked (and audited)
     * before the transaction, and again under the device row lock that
     * attachDevice() also takes.
     */
    public function disable(Device $device, User $actor, ?string $reason = null): Device
    {
        if ($device->isCommittedToLiveRental()) {
            AuditLogger::log(
                action: 'device.disable_denied',
                resourceType: 'Device',
                resourceId: $device->id,
                result: AuditLogger::RESULT_DENIED,
                context: ['reason' => 'device_in_live_rental'],
                actor: $actor,
            );

            throw new \RuntimeException('این دستگاه در اجاره است و تا پایان اجاره قابل غیرفعال‌سازی یا بازپس‌گیری نیست.');
        }

        return DB::transaction(function () use ($device, $actor, $reason) {
            if (Device::where('id', $device->id)->lockForUpdate()->firstOrFail()->isCommittedToLiveRental()) {
                throw new \RuntimeException('این دستگاه در اجاره است و تا پایان اجاره قابل غیرفعال‌سازی یا بازپس‌گیری نیست.');
            }

            return $this->transition($device, DeviceState::Disabled, $actor, [
                'disabled_at' => now(),
                'disabled_reason' => $reason,
            ], $reason);
        });
    }

    private function create(
        Product $product,
        string $serial,
        DeviceOwnership $ownership,
        ?Owner $owner,
        array $attributes,
        ?User $actor,
    ): Device {
        $serial = trim($serial);

        if ($serial === '') {
            throw new \RuntimeException('وارد کردن شماره سریال دستگاه الزامی است.');
        }

        $normalized = Device::normalizeSerial($serial);

        if ($normalized === '') {
            throw new \RuntimeException('شماره سریال وارد شده معتبر نیست.');
        }

        try {
            $device = DB::transaction(function () use ($product, $serial, $normalized, $ownership, $owner, $attributes) {
                // Checked here for a clear Persian message; the unique index is
                // what actually guarantees it under concurrency, and the catch
                // below turns the race into the same message.
                if (Device::where('serial_normalized', $normalized)->exists()) {
                    throw new \RuntimeException('دستگاهی با این شماره سریال قبلاً ثبت شده است.');
                }

                $device = new Device([
                    'product_id' => $product->id,
                    'serial_number' => $serial,
                    'serial_normalized' => $normalized,
                    'condition' => $attributes['condition'] ?? null,
                    'notes' => $attributes['notes'] ?? null,
                ]);

                // Not fillable: ownership and review state are never request data.
                $device->ownership = $ownership;
                $device->owner_id = $owner?->id;
                $device->state = DeviceState::PendingReview;
                $device->verification_state = DeviceVerificationState::Unverified;
                $device->registered_at = now();
                $device->save();

                return $device;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateSerial($e)) {
                throw new \RuntimeException('دستگاهی با این شماره سریال قبلاً ثبت شده است.');
            }

            throw $e;
        }

        AuditLogger::log(
            action: 'device.registered',
            resourceType: 'Device',
            resourceId: $device->id,
            context: [
                'product_id' => $product->id,
                'ownership' => $ownership->value,
                'owner_id' => $owner?->id,
                'state' => $device->state->value,
                // The serial itself is a physical identifier; only the mask is
                // recorded, in line with the audit redaction discipline.
                'serial_mask' => $device->maskedSerial(),
            ],
            actor: $actor,
        );

        return $device;
    }

    private function transition(Device $device, DeviceState $target, User $actor, array $fields, ?string $reason): Device
    {
        return DB::transaction(function () use ($device, $target, $actor, $fields, $reason) {
            $locked = Device::where('id', $device->id)->lockForUpdate()->first();

            if ($locked->state === $target) {
                $device->setRawAttributes($locked->getAttributes(), true);

                return $device;
            }

            if (! $locked->state->canTransitionTo($target)) {
                throw new \RuntimeException('این دستگاه در وضعیت لازم برای این تغییر نیست.');
            }

            $from = $locked->state;

            foreach ($fields as $key => $value) {
                $locked->{$key} = $value;
            }

            $locked->state = $target;
            $locked->save();

            AuditLogger::log(
                action: 'device.'.$target->value,
                resourceType: 'Device',
                resourceId: $locked->id,
                context: [
                    'from' => $from->value,
                    'to' => $target->value,
                    'reason' => $reason,
                    'ownership' => $locked->ownership->value,
                    'owner_id' => $locked->owner_id,
                ],
                actor: $actor,
            );

            $device->setRawAttributes($locked->getAttributes(), true);

            return $device;
        });
    }

    private function isDuplicateSerial(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains((string) $e->getMessage(), 'serial_normalized');
    }
}
