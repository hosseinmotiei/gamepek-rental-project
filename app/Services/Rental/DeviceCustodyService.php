<?php

namespace App\Services\Rental;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\CustodyTransferType;
use App\Enums\DeviceOwnership;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\RentalOperation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `device_custody_transfers`.
 *
 * CUSTODY IS NOT OWNERSHIP, and this class is where that is enforced rather
 * than merely documented. It has no code path that writes `devices.owner_id` or
 * `devices.ownership`, and every method asserts afterwards that neither moved.
 * An owner who lends GamePek a console for a rental has not sold it.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 *  - No GamePek -> GamePek transfer. A GamePek-owned device is already in
 *    GamePek custody; writing a row for it would record a handover nobody
 *    performed. Device::currentCustody() covers that case with no row at all.
 *
 *  - No delivery, customer return or owner return. Those legs are real and
 *    unimplemented; CustodyTransferType declares only the one that works.
 *
 *  - No legal claim. `acknowledged` is the owner confirming GamePek's record.
 *    It is not a signature, not acceptance, and says nothing about the
 *    condition of the device. Signature and receipt requirements are an open
 *    legal gate (docs/business/CONFIRMED_DECISIONS.md section 4.2), and
 *    inspection belongs to a later phase.
 */
class DeviceCustodyService
{
    public function __construct(private RentalOperationService $operations) {}

    /**
     * Record that GamePek has asked the owner for the device.
     *
     * NOT possession. This is the paperwork opening, and the owner sees it in
     * their panel. Called by RentalOperationService's caller when a pickup
     * starts; idempotent, so starting a pickup twice yields one request.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function requestFromOwner(RentalOperation $operation, User $actor): DeviceCustodyTransfer
    {
        return DB::transaction(function () use ($operation, $actor) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            $existing = $locked->custodyTransfer()->first();

            if ($existing) {
                return $existing;
            }

            $device = $this->assertOwnerPickup($locked);

            try {
                // Assigned attribute-by-attribute, never mass-assigned: the
                // model guards everything, so actors and state cannot arrive
                // from request data even by accident.
                $transfer = new DeviceCustodyTransfer;
                $transfer->reference_number = DeviceCustodyTransfer::generateReference();
                $transfer->device_id = $device->id;
                $transfer->rental_operation_id = $locked->id;
                $transfer->rental_reservation_id = $locked->rental_reservation_id;
                $transfer->transfer_type = CustodyTransferType::OwnerToGamePek;

                // Derived from the type, not chosen independently, so the pair
                // cannot drift from what the type means. The CHECK constraint
                // enforces the same thing at the database.
                $transfer->from_actor_type = CustodyTransferType::OwnerToGamePek->source();
                $transfer->to_actor_type = CustodyTransferType::OwnerToGamePek->destination();
                $transfer->from_owner_id = $device->owner_id;
                // GamePek has no owner row by design.
                $transfer->to_owner_id = null;

                $transfer->state = CustodyTransferState::Requested;
                $transfer->initiated_at = now();
                $transfer->initiated_by_user_id = $actor->id;

                $this->assertActorsMatchType($transfer);

                $transfer->save();
            } catch (QueryException $e) {
                if ($this->isDuplicateTransfer($e)) {
                    return $locked->custodyTransfer()->firstOrFail();
                }

                throw $e;
            }

            $this->audit('custody.requested', $transfer, $device, $actor);

            return $transfer;
        });
    }

    /**
     * GamePek records taking physical possession, and the pickup closes.
     *
     * This is the moment custody actually moves. Ownership does not: the device
     * row is not written at all, and that is asserted below rather than trusted.
     *
     * Concurrency: the operation row is locked first, so two operators pressing
     * "received" at once serialise here. The loser finds the transfer already
     * moved and gets a safe Persian conflict message, not a second record --
     * and unique(rental_operation_id) backs that up in the database.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function recordHandoverToGamePek(RentalOperation $operation, User $actor, ?string $notes = null): DeviceCustodyTransfer
    {
        return DB::transaction(function () use ($operation, $actor, $notes) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== RentalOperationState::InProgress) {
                throw new \RuntimeException('این عملیات در وضعیت لازم برای ثبت تحویل نیست.');
            }

            $device = $this->assertOwnerPickup($locked);

            $transfer = $locked->custodyTransfer()->lockForUpdate()->first()
                ?? throw new \RuntimeException('برای این عملیات درخواست تحویلی ثبت نشده است.');

            if ($transfer->isPossessionMoved()) {
                throw new \RuntimeException('تحویل این دستگاه قبلاً ثبت شده است.');
            }

            if (! $transfer->state->canTransitionTo(CustodyTransferState::Transferred)) {
                throw new \RuntimeException('وضعیت تحویل این دستگاه اجازه این تغییر را نمی‌دهد.');
            }

            // The transfer and its operation must name the SAME console.
            // attachDevice() refuses while a task is in progress, so this is
            // not reachable through the normal flow -- it is asserted anyway
            // because "not reachable today" is a fact about the current code,
            // not an invariant, and a silent mismatch here would record the
            // wrong device as having changed hands.
            if ($transfer->device_id !== $locked->device_id) {
                throw new \RuntimeException('دستگاه ثبت‌شده در سابقه تحویل با دستگاه این عملیات یکسان نیست.');
            }

            $this->assertActorsMatchType($transfer);

            $ownershipBefore = [$device->owner_id, $device->ownership->value];

            $transfer->state = CustodyTransferState::Transferred;
            $transfer->transferred_at = now();
            $transfer->transferred_by_user_id = $actor->id;

            if ($notes !== null && trim($notes) !== '') {
                $transfer->notes = mb_substr(trim($notes), 0, 2000);
            }

            $transfer->save();

            $this->audit('custody.transferred', $transfer, $device, $actor);

            // The pickup is done exactly when possession moved, and not before.
            $this->operations->completeAfterCustody($locked, $actor);

            $this->assertOwnershipUnchanged($device, $ownershipBefore);

            return $transfer;
        });
    }

    /**
     * The owner confirms GamePek's record of the handover.
     *
     * Changes nothing about custody -- possession already moved -- and carries
     * no legal weight. It exists so the counterparty has a way to say the
     * record matches what they did.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function acknowledgeByOwner(DeviceCustodyTransfer $transfer, User $actor): DeviceCustodyTransfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $locked = DeviceCustodyTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === CustodyTransferState::Acknowledged) {
                $transfer->setRawAttributes($locked->getAttributes(), true);

                return $transfer;
            }

            if (! $locked->state->canTransitionTo(CustodyTransferState::Acknowledged)) {
                throw new \RuntimeException('این تحویل هنوز ثبت نشده است و قابل تأیید نیست.');
            }

            $device = $locked->device()->firstOrFail();
            $ownershipBefore = [$device->owner_id, $device->ownership->value];
            $custodyBefore = $device->currentCustody();

            $locked->state = CustodyTransferState::Acknowledged;
            $locked->acknowledged_at = now();
            $locked->acknowledged_by_user_id = $actor->id;
            $locked->save();

            $this->audit('custody.acknowledged', $locked, $device, $actor);

            $this->assertOwnershipUnchanged($device, $ownershipBefore);

            // Acknowledgement is a CONFIRMATION, not a second handover. It
            // updates the state of the existing row rather than appending a new
            // one, so possession must read exactly the same before and after.
            // Asserted rather than assumed: if a future change ever made
            // acknowledging move custody, that would be a device appearing to
            // change hands twice for one physical event.
            if ($device->fresh()->currentCustody() !== $custodyBefore) {
                throw new \RuntimeException('تأیید مالک نباید وضعیت در اختیار بودن دستگاه را تغییر دهد.');
            }

            $transfer->setRawAttributes($locked->getAttributes(), true);

            return $transfer;
        });
    }

    /**
     * The declared transfer type and the actual actors must agree.
     *
     * A row claiming `owner_to_gamepek` while pointing customer -> owner would
     * be a device silently changing hands. Checked before every write and
     * enforced again by a CHECK constraint, because the service being the only
     * writer is a fact about today's code rather than an invariant.
     */
    private function assertActorsMatchType(DeviceCustodyTransfer $transfer): void
    {
        if (! $transfer->actorsMatchType()) {
            throw new \RuntimeException('طرفین ثبت‌شده برای این نوع انتقال تحویل معتبر نیستند.');
        }

        $ownerSideMissing = $transfer->from_actor_type === CustodyActor::Owner && $transfer->from_owner_id === null;
        $ownerSideInvented = $transfer->from_actor_type !== CustodyActor::Owner && $transfer->from_owner_id !== null;
        $toSideMissing = $transfer->to_actor_type === CustodyActor::Owner && $transfer->to_owner_id === null;
        // GamePek deliberately has no owner row; naming one here would be
        // inventing a fake owner account for first-party stock.
        $toSideInvented = $transfer->to_actor_type !== CustodyActor::Owner && $transfer->to_owner_id !== null;

        if ($ownerSideMissing || $ownerSideInvented || $toSideMissing || $toSideInvented) {
            throw new \RuntimeException('ارجاع مالک در سابقه تحویل با طرفین انتقال هم‌خوان نیست.');
        }
    }

    /**
     * Everything that must be true before an owner handover may be recorded.
     *
     * Checked here rather than at the call sites so no future controller can
     * skip one: the task really is an owner pickup, a concrete device is
     * attached, that device really belongs to a third-party owner, and the
     * operation and the reservation name the same physical console.
     */
    private function assertOwnerPickup(RentalOperation $operation): Device
    {
        if ($operation->type !== RentalOperationType::OwnerDevicePickup) {
            throw new \RuntimeException('این عملیات از نوع تحویل گرفتن دستگاه از مالک نیست.');
        }

        $device = $operation->device()->first();

        if ($device === null) {
            throw new \RuntimeException('تا زمانی که دستگاه مشخص نشده باشد، تحویل قابل ثبت نیست.');
        }

        if ($device->ownership !== DeviceOwnership::Owner || $device->owner_id === null) {
            // GamePek's own stock is already in GamePek custody. Writing a
            // transfer for it would record a handover that never happened.
            throw new \RuntimeException('این دستگاه متعلق به گیم‌پک است و تحویل از مالک ندارد.');
        }

        if ($operation->owner_id !== null && $operation->owner_id !== $device->owner_id) {
            throw new \RuntimeException('مالک ثبت‌شده برای این عملیات با مالک دستگاه یکسان نیست.');
        }

        $reservation = $operation->reservation()->first();

        if ($reservation === null || $reservation->device_id !== $device->id) {
            throw new \RuntimeException('دستگاه این عملیات با رزرو مربوطه هم‌خوان نیست.');
        }

        return $device;
    }

    /**
     * The invariant this whole domain exists for.
     *
     * Asserted after every write instead of assumed. If a future change ever
     * makes custody touch ownership, this fails loudly here rather than
     * silently transferring someone's console to GamePek.
     */
    private function assertOwnershipUnchanged(Device $device, array $before): void
    {
        $fresh = Device::where('id', $device->id)->firstOrFail();

        if ([$fresh->owner_id, $fresh->ownership->value] !== $before) {
            throw new \RuntimeException('انتقال تحویل نباید مالکیت دستگاه را تغییر دهد.');
        }
    }

    private function audit(string $action, DeviceCustodyTransfer $transfer, Device $device, User $actor): void
    {
        AuditLogger::log(
            action: $action,
            resourceType: 'DeviceCustodyTransfer',
            resourceId: $transfer->id,
            context: [
                // Operational handle only -- not a receipt, no legal effect.
                'reference_number' => $transfer->reference_number,
                'device_id' => $device->id,
                // Never the raw serial -- a physical identifier stays masked in
                // ordinary audit context, as with device.registered.
                'device_serial_mask' => $device->maskedSerial(),
                'rental_operation_id' => $transfer->rental_operation_id,
                'rental_reservation_id' => $transfer->rental_reservation_id,
                'from' => $transfer->from_actor_type->value,
                'to' => $transfer->to_actor_type->value,
                'transfer_type' => $transfer->transfer_type->value,
                'state' => $transfer->state->value,
                // Ownership recorded alongside custody so the audit trail shows
                // plainly that one moved and the other did not.
                'ownership' => $device->ownership->value,
                'owner_id' => $device->owner_id,
            ],
            actor: $actor,
        );
    }

    private function isDuplicateTransfer(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains((string) $e->getMessage(), 'device_custody_transfers_operation_uq');
    }
}
