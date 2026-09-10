<?php

namespace App\Services\Rental;

use App\Enums\DeviceOwnership;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `rental_operations.state`.
 *
 * Same single-writer discipline as RentalChainOrchestrator and
 * DeviceRegistrationService, for the same reason: an operational outcome must
 * never be settable from a request. Controllers call the verbs below; nothing
 * anywhere assigns `state` directly, and the model does not make it fillable.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 *  - It never chooses a device. Which free device serves a paid reservation is
 *    an undecided policy (see the rental_reservations device_id migration).
 *    attachDevice() takes the device a human named and validates it; there is
 *    no "pick the first approved one" path, on purpose.
 *
 *  - It acts on nothing when a pickup fails. No refund, no owner penalty, no
 *    replacement, no reservation cancellation, no suspension. All of those are
 *    undecided business policy. The failure is recorded, audited and surfaced
 *    to admin, and that is the whole behaviour.
 *
 *  - It creates no custody record. Possession is DeviceCustodyService's
 *    business; this class only refuses to complete a pickup that has no
 *    recorded handover behind it.
 */
class RentalOperationService
{
    /**
     * Create the pickup task for a reservation whose payment has cleared.
     *
     * Called from inside the reservation-creation transaction, so an operation
     * cannot exist for a reservation that failed to be written, and a payment
     * that never settled produces neither.
     *
     * Idempotent twice over: an existing task is returned untouched, and
     * unique(rental_reservation_id, type) catches the concurrent case that
     * check cannot. A replayed gateway callback therefore produces exactly one
     * task, not two.
     *
     * The task is born with NO device, because none has been allocated, and
     * lands in `awaiting_device_allocation` rather than pretending otherwise.
     */
    public function openPickupForReservation(RentalReservation $reservation, ?User $actor = null): RentalOperation
    {
        $existing = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            $operation = new RentalOperation;
            $operation->operation_number = RentalOperation::generateNumber();
            $operation->rental_reservation_id = $reservation->id;
            $operation->rental_application_id = $reservation->rental_application_id;
            $operation->type = RentalOperationType::OwnerDevicePickup;
            $operation->state = RentalOperationState::Pending;
            $operation->created_by_user_id = $actor?->id;

            // A reservation carries no device today; if a later phase ever
            // allocates one before payment, the task starts already attached
            // rather than asking for an allocation that already happened.
            $operation->device_id = $reservation->device_id;
            $operation->save();
        } catch (QueryException $e) {
            if ($this->isDuplicateOperation($e)) {
                return RentalOperation::where('rental_reservation_id', $reservation->id)
                    ->where('type', RentalOperationType::OwnerDevicePickup->value)
                    ->firstOrFail();
            }

            throw $e;
        }

        AuditLogger::log(
            action: 'operation.created',
            resourceType: 'RentalOperation',
            resourceId: $operation->id,
            context: [
                'operation_number' => $operation->operation_number,
                'type' => $operation->type->value,
                'rental_reservation_id' => $reservation->id,
                'rental_application_id' => $reservation->rental_application_id,
                'device_id' => $operation->device_id,
            ],
            actor: $actor,
        );

        // Resolve the birth state honestly: with no device there is nothing to
        // plan, so the task says exactly that.
        if (! $operation->hasDevice()) {
            $operation = $this->transition(
                $operation,
                RentalOperationState::AwaitingDeviceAllocation,
                $actor,
                'awaiting_device_allocation',
            );
        }

        return $operation;
    }

    /**
     * Attach the concrete physical device a human chose.
     *
     * NOTHING IS CHOSEN HERE. The caller names the device; this validates it
     * and records it. There is deliberately no overload that finds a device on
     * its own -- allocation is an open policy that interacts with the 35/65
     * split and daily settlement, and guessing it would quietly pick winners
     * among owners.
     *
     * The outcome depends on who owns the device, which is the first moment the
     * task can know whether an owner pickup is needed at all:
     *
     *   owner-owned   -> scheduled; a real handover has to happen
     *   GamePek-owned -> not_required; custody is already GamePek and no
     *                    transfer is written (see DeviceCustodyService)
     *
     * @throws \RuntimeException with a Persian message
     */
    public function attachDevice(RentalOperation $operation, Device $device, User $actor): RentalOperation
    {
        return DB::transaction(function () use ($operation, $device, $actor) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            if ($locked->isTerminal()) {
                throw new \RuntimeException('این عملیات بسته شده است و تغییر دستگاه ممکن نیست.');
            }

            if ($locked->device_id !== null && $locked->device_id !== $device->id) {
                throw new \RuntimeException('برای این عملیات دستگاه دیگری ثبت شده است.');
            }

            if ($locked->state === RentalOperationState::InProgress) {
                throw new \RuntimeException('عملیات آغاز شده است و تغییر دستگاه ممکن نیست.');
            }

            $reservation = $locked->reservation()->lockForUpdate()->firstOrFail();

            // The physical device must actually be an instance of the catalog
            // item the customer paid for.
            if ($device->product_id !== $reservation->product_id) {
                throw new \RuntimeException('این دستگاه از مدل رزروشده نیست.');
            }

            if (! $device->isRentable()) {
                throw new \RuntimeException('این دستگاه تأیید نشده است و قابل تخصیص نیست.');
            }

            if ($device->ownership === DeviceOwnership::Owner && $device->owner_id === null) {
                // The CHECK constraint on `devices` makes this unreachable; it
                // is asserted anyway rather than assumed, because everything
                // below depends on the owner being real.
                throw new \RuntimeException('مالک این دستگاه مشخص نیست.');
            }

            $locked->device_id = $device->id;
            $locked->owner_id = $device->owner_id;

            // Keep the reservation pointing at the same physical device. The
            // allocation POLICY is unchanged -- still an explicit human choice,
            // never automatic -- but the reservation and its operation must not
            // be able to name two different consoles.
            $reservation->device_id = $device->id;
            $reservation->save();

            $target = $device->isOwnedByGamePek()
                ? RentalOperationState::NotRequired
                : RentalOperationState::Scheduled;

            $this->assertCanTransition($locked, $target);

            $locked->state = $target;

            if ($target === RentalOperationState::NotRequired) {
                // Not "completed": no handover happened, because none was
                // needed. Nothing is charged or triggered by this.
                $locked->completed_at = now();
                $locked->completed_by_user_id = $actor->id;
            }

            $locked->save();

            AuditLogger::log(
                action: 'operation.device_attached',
                resourceType: 'RentalOperation',
                resourceId: $locked->id,
                context: [
                    'operation_number' => $locked->operation_number,
                    'device_id' => $device->id,
                    // Never the raw serial.
                    'device_serial_mask' => $device->maskedSerial(),
                    'ownership' => $device->ownership->value,
                    'owner_id' => $device->owner_id,
                    'to' => $target->value,
                ],
                actor: $actor,
            );

            $operation->setRawAttributes($locked->getAttributes(), true);

            return $operation;
        });
    }

    /** Set or clear the planned pickup time. Does not move the state ladder. */
    public function schedule(RentalOperation $operation, User $actor, ?string $scheduledAt = null, ?int $assignedToUserId = null): RentalOperation
    {
        return DB::transaction(function () use ($operation, $actor, $scheduledAt, $assignedToUserId) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== RentalOperationState::Scheduled) {
                throw new \RuntimeException('این عملیات در وضعیت لازم برای زمان‌بندی نیست.');
            }

            $locked->scheduled_at = $scheduledAt ? now()->parse($scheduledAt) : null;
            $locked->assigned_to_user_id = $assignedToUserId;
            $locked->save();

            AuditLogger::log(
                action: 'operation.scheduled',
                resourceType: 'RentalOperation',
                resourceId: $locked->id,
                context: [
                    'operation_number' => $locked->operation_number,
                    'scheduled_at' => $locked->scheduled_at?->toDateTimeString(),
                    'assigned_to_user_id' => $locked->assigned_to_user_id,
                ],
                actor: $actor,
            );

            $operation->setRawAttributes($locked->getAttributes(), true);

            return $operation;
        });
    }

    /**
     * Begin the pickup: GamePek is now actively trying to obtain the device.
     *
     * Requires a concrete device. A pickup with nothing to pick up is not work
     * that can be started.
     */
    public function start(RentalOperation $operation, User $actor): RentalOperation
    {
        return DB::transaction(function () use ($operation, $actor) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasDevice()) {
                throw new \RuntimeException('تا زمانی که دستگاه مشخص نشده باشد، عملیات آغاز نمی‌شود.');
            }

            $this->assertCanTransition($locked, RentalOperationState::InProgress);

            $locked->state = RentalOperationState::InProgress;
            $locked->started_at = $locked->started_at ?? now();
            $locked->failure_reason = null;
            $locked->failed_at = null;
            $locked->save();

            AuditLogger::log(
                action: 'operation.started',
                resourceType: 'RentalOperation',
                resourceId: $locked->id,
                context: [
                    'operation_number' => $locked->operation_number,
                    'device_id' => $locked->device_id,
                ],
                actor: $actor,
            );

            $operation->setRawAttributes($locked->getAttributes(), true);

            return $operation;
        });
    }

    /**
     * Close a pickup as done.
     *
     * INTERNAL to the custody flow. It refuses unless possession has actually
     * been recorded as moved, so "completed" can never mean less than "GamePek
     * has the device". DeviceCustodyService calls this inside the same
     * transaction as the handover it just wrote.
     *
     * The caller must already hold the row lock.
     */
    public function completeAfterCustody(RentalOperation $locked, User $actor): RentalOperation
    {
        if (! $locked->hasDevice()) {
            throw new \RuntimeException('تا زمانی که دستگاه مشخص نشده باشد، عملیات تکمیل نمی‌شود.');
        }

        $transfer = $locked->custodyTransfer()->first();

        if ($transfer === null || ! $transfer->isPossessionMoved()) {
            throw new \RuntimeException('تحویل فیزیکی دستگاه ثبت نشده است.');
        }

        $this->assertCanTransition($locked, RentalOperationState::Completed);

        $locked->state = RentalOperationState::Completed;
        $locked->completed_at = now();
        $locked->completed_by_user_id = $actor->id;
        $locked->save();

        AuditLogger::log(
            action: 'operation.completed',
            resourceType: 'RentalOperation',
            resourceId: $locked->id,
            context: [
                'operation_number' => $locked->operation_number,
                'device_id' => $locked->device_id,
                'owner_id' => $locked->owner_id,
                'custody_transfer_id' => $transfer->id,
            ],
            actor: $actor,
        );

        return $locked;
    }

    /**
     * Record that the pickup did not happen.
     *
     * POLICY GATE: nothing follows from this. No refund is issued, no owner is
     * penalised or suspended, no reservation is cancelled, no replacement
     * device is chosen. Every one of those is an undecided business rule
     * (docs/business/CONFIRMED_DECISIONS.md section 4). The failure is recorded
     * with its reason and actor, audited, and shown to admin.
     */
    public function fail(RentalOperation $operation, User $actor, string $reason): RentalOperation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \RuntimeException('ثبت دلیل ناموفق بودن عملیات الزامی است.');
        }

        return DB::transaction(function () use ($operation, $actor, $reason) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($locked, RentalOperationState::Failed);

            $locked->state = RentalOperationState::Failed;
            $locked->failed_at = now();
            $locked->failure_reason = mb_substr($reason, 0, 500);
            $locked->save();

            AuditLogger::log(
                action: 'operation.failed',
                resourceType: 'RentalOperation',
                resourceId: $locked->id,
                result: AuditLogger::RESULT_FAILURE,
                context: [
                    'operation_number' => $locked->operation_number,
                    'device_id' => $locked->device_id,
                    'owner_id' => $locked->owner_id,
                    'reason' => $locked->failure_reason,
                ],
                actor: $actor,
            );

            $operation->setRawAttributes($locked->getAttributes(), true);

            return $operation;
        });
    }

    private function assertCanTransition(RentalOperation $operation, RentalOperationState $target): void
    {
        if ($operation->state->isTerminal()) {
            throw new \RuntimeException('این عملیات بسته شده است و قابل بازگشایی نیست.');
        }

        if (! $operation->state->canTransitionTo($target)) {
            throw new \RuntimeException('این عملیات در وضعیت لازم برای این تغییر نیست.');
        }
    }

    private function transition(RentalOperation $operation, RentalOperationState $target, ?User $actor, string $action): RentalOperation
    {
        $this->assertCanTransition($operation, $target);

        $from = $operation->state;
        $operation->state = $target;
        $operation->save();

        AuditLogger::log(
            action: 'operation.'.$action,
            resourceType: 'RentalOperation',
            resourceId: $operation->id,
            context: [
                'operation_number' => $operation->operation_number,
                'from' => $from->value,
                'to' => $target->value,
            ],
            actor: $actor,
        );

        return $operation;
    }

    private function isDuplicateOperation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains((string) $e->getMessage(), 'rental_operations_reservation_type_uq');
    }
}
