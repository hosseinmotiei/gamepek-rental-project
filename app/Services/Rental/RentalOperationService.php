<?php

namespace App\Services\Rental;

use App\Enums\CustodyActor;
use App\Enums\DeviceOwnership;
use App\Enums\RentalApplicationState;
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
 *    no "pick the first approved one" path, on purpose. What it DOES refuse is
 *    a human naming a device that is already committed to another blocking
 *    reservation for an overlapping date range -- a safety check, not a
 *    selection policy: it never picks a device, it only refuses an unsafe one.
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
     * The orchestrator is injected, not bypassed: when a delivery or a return
     * completes, the resulting application-state move is asked of
     * RentalChainOrchestrator, which remains the ONLY writer of
     * `rental_applications.state`. This service never assigns that column.
     */
    public function __construct(private RentalChainOrchestrator $orchestrator) {}

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
        return $this->openOperation($reservation, RentalOperationType::OwnerDevicePickup, $actor);
    }

    /**
     * Open the task for handing the device to the customer.
     *
     * CONFIRMED RULE: completing this task is the only thing that starts a
     * rental (Approved -> Active). It is therefore refused unless the
     * application has actually been approved -- an admin should learn that
     * before driving to the customer, not when the completion is rejected.
     *
     * A device must already be allocated to the reservation: there is nothing
     * to deliver otherwise, and this service still never chooses one.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function openDeliveryForReservation(RentalReservation $reservation, User $actor): RentalOperation
    {
        $application = $reservation->application()->firstOrFail();

        if ($application->state !== RentalApplicationState::Approved) {
            throw new \RuntimeException('تا زمانی که درخواست تأیید نهایی نشده باشد، تحویل به مشتری ثبت نمی‌شود.');
        }

        if ($reservation->device_id === null) {
            throw new \RuntimeException('تا زمانی که دستگاه مشخص نشده باشد، تحویل به مشتری برنامه‌ریزی نمی‌شود.');
        }

        return $this->openOperation($reservation, RentalOperationType::CustomerDelivery, $actor);
    }

    /**
     * Open the task for taking the device back from the customer.
     *
     * CONFIRMED RULE: the return is arranged through support, so this is
     * opened by staff rather than by the customer. Completing it is what
     * moves Active -> Returned.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function openReturnForReservation(RentalReservation $reservation, User $actor): RentalOperation
    {
        $application = $reservation->application()->firstOrFail();

        if ($application->state !== RentalApplicationState::Active) {
            throw new \RuntimeException('بازگشت دستگاه فقط برای اجاره فعال ثبت می‌شود.');
        }

        if ($reservation->device_id === null) {
            throw new \RuntimeException('برای این رزرو دستگاهی ثبت نشده است.');
        }

        return $this->openOperation($reservation, RentalOperationType::CustomerReturn, $actor);
    }

    /**
     * Open the task for handing a returned console back to its owner (C-40).
     *
     * Only once the rental is Returned -- before that the device is with the
     * customer, and there is no customer -> owner leg. Only for an owner's
     * device: GamePek's own stock is already where it belongs. The custody
     * service re-checks all of this, plus the custody itself, when the leg is
     * opened and recorded.
     *
     * Nothing here waits on, or is gated by, the owner's two-hour defect
     * window. No rule links the two and none is invented.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function openOwnerReturnForReservation(RentalReservation $reservation, User $actor): RentalOperation
    {
        $application = $reservation->application()->firstOrFail();

        if ($application->state !== RentalApplicationState::Returned) {
            throw new \RuntimeException('بازگرداندن دستگاه به مالک فقط پس از دریافت دستگاه از مشتری ممکن است.');
        }

        $device = $reservation->device()->first();

        if ($device === null) {
            throw new \RuntimeException('برای این رزرو دستگاهی ثبت نشده است.');
        }

        if ($device->ownership !== DeviceOwnership::Owner || $device->owner_id === null) {
            throw new \RuntimeException('این دستگاه متعلق به گیم‌پک است و بازگرداندن به مالک ندارد.');
        }

        return $this->openOperation($reservation, RentalOperationType::OwnerReturn, $actor);
    }

    /**
     * The one creator of operational tasks, for every type.
     *
     * Idempotent twice over: an existing task of this type is returned
     * untouched, and unique(rental_reservation_id, type) catches the
     * concurrent case that check cannot.
     */
    private function openOperation(
        RentalReservation $reservation,
        RentalOperationType $type,
        ?User $actor,
    ): RentalOperation {
        $existing = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', $type->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            $operation = new RentalOperation;
            $operation->operation_number = RentalOperation::generateNumber();
            $operation->rental_reservation_id = $reservation->id;
            $operation->rental_application_id = $reservation->rental_application_id;
            $operation->type = $type;
            $operation->state = RentalOperationState::Pending;
            $operation->created_by_user_id = $actor?->id;

            // A reservation carries no device at payment time; if a later phase
            // ever allocates one before payment, the task starts already
            // attached rather than asking for an allocation that already
            // happened. Delivery and return are always opened with one.
            $operation->device_id = $reservation->device_id;
            $operation->owner_id = $reservation->device?->owner_id;
            $operation->save();
        } catch (QueryException $e) {
            if ($this->isDuplicateOperation($e)) {
                return RentalOperation::where('rental_reservation_id', $reservation->id)
                    ->where('type', $type->value)
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
            return $this->transition(
                $operation,
                RentalOperationState::AwaitingDeviceAllocation,
                $actor,
                'awaiting_device_allocation',
            );
        }

        // Delivery and return are born with the device already known, so the
        // rung the pickup reaches through attachDevice() is reached here
        // instead. The pickup's own path is untouched: it is created before any
        // device exists and still waits for a human to name one.
        if ($type !== RentalOperationType::OwnerDevicePickup) {
            return $this->transition($operation, RentalOperationState::Scheduled, $actor, 'scheduled');
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
        $reservation = $operation->reservation()->firstOrFail();

        // Pre-transaction, unlocked check for the common (non-racing) case,
        // so its denial can be audited durably. An audit row written INSIDE
        // the transaction below and then rolled back by the throw that
        // follows it would simply vanish -- exactly the reasoning
        // ContractService::accept()/sign() already document for the same
        // shape of problem in this codebase. The lock-protected re-check
        // inside the transaction (below) is the race-safety net; a request
        // that loses that race is refused without a second audit row, the
        // same way ContractService's inner lock does not re-audit its
        // outer, already-audited denial.
        if ($this->deviceOverlapsAnotherBlockingReservation($device->id, $reservation)) {
            AuditLogger::log(
                action: 'operation.device_attach_denied',
                resourceType: 'RentalOperation',
                resourceId: $operation->id,
                result: AuditLogger::RESULT_DENIED,
                context: [
                    'operation_number' => $operation->operation_number,
                    'device_id' => $device->id,
                    'device_serial_mask' => $device->maskedSerial(),
                    'reason' => 'device_overlaps_another_blocking_reservation',
                ],
                actor: $actor,
            );

            throw new \RuntimeException('این دستگاه برای بازه زمانی این رزرو در دسترس نیست.');
        }

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

            // Lock the candidate device row itself: two concurrent attachDevice()
            // calls naming the SAME device for two DIFFERENT (and possibly
            // conflicting) reservations must serialise here, the same way two
            // racing payments already serialise on the product row in
            // RentalReservationService::materialiseAfterPayment().
            $device = Device::where('id', $device->id)->lockForUpdate()->firstOrFail();

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

            // Race guard: re-check under the device lock. Reuses the same
            // predicate as the pre-check above -- no second overlap concept,
            // and this does not touch product-level availability/capacity
            // semantics (RentalAvailabilityService and scopeOverlapping()'s
            // product-level call site are untouched).
            if ($this->deviceOverlapsAnotherBlockingReservation($device->id, $reservation)) {
                throw new \RuntimeException('این دستگاه برای بازه زمانی این رزرو در دسترس نیست.');
            }

            $locked->device_id = $device->id;
            $locked->owner_id = $device->owner_id;

            // Keep the reservation pointing at the same physical device. The
            // allocation POLICY is unchanged -- still an explicit human choice,
            // never automatic -- but the reservation and its operation must not
            // be able to name two different consoles.
            $reservation->device_id = $device->id;
            $reservation->save();

            // No pickup is needed when GamePek already holds the device --
            // either it is GamePek's own stock, or it is an owner's console
            // that GamePek still has (e.g. kept after an earlier rental's
            // return instead of going back to the owner). Scheduling a pickup
            // then would ask the owner for something they do not have, and
            // completing it would record a handover nobody made.
            $custody = $device->currentCustody();

            $target = ($device->isOwnedByGamePek() || $custody === CustodyActor::GamePek)
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
                    'custody' => $custody->value,
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

        $this->advanceLifecycleAfter($locked, $actor);

        return $locked;
    }

    /**
     * The completed task's effect on the rental's own lifecycle.
     *
     * CONFIRMED RULES, and the only two application-state effects an
     * operational task has:
     *
     *   customer_delivery completed -> Approved becomes Active
     *   customer_return   completed -> Active becomes Returned
     *
     * An owner pickup completing changes no application state, exactly as
     * before. The move itself is performed by RentalChainOrchestrator (sole
     * writer of `state`, adjacency-checked, audited); this method only names
     * which rung the completed task corresponds to. It runs INSIDE the
     * caller's transaction, so an application that is not in the state the
     * move requires rolls the whole completion back rather than leaving a
     * completed handover attached to a rental that never started.
     */
    private function advanceLifecycleAfter(RentalOperation $operation, User $actor): void
    {
        $target = match ($operation->type) {
            RentalOperationType::CustomerDelivery => RentalApplicationState::Active,
            RentalOperationType::CustomerReturn => RentalApplicationState::Returned,
            default => null,
        };

        if ($target === null) {
            return;
        }

        $this->orchestrator->transitionPostApproval(
            $operation->application()->firstOrFail(),
            $target,
            $actor,
        );
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

    /**
     * Is $deviceId already committed to a DIFFERENT blocking reservation that
     * overlaps $reservation's own dates?
     *
     * Reuses the one existing overlap predicate
     * (RentalReservation::scopeOverlapping()) and the one existing
     * blocking-state definition (scopeBlocking()) -- this only layers a
     * device_id filter on top of both, so there is still exactly one overlap
     * concept in the codebase. $reservation itself is excluded so a device
     * already attached to it is never flagged as conflicting with itself.
     */
    private function deviceOverlapsAnotherBlockingReservation(int $deviceId, RentalReservation $reservation): bool
    {
        return RentalReservation::overlapping(
            $reservation->product_id,
            $reservation->start_date->toDateString(),
            $reservation->end_date->toDateString(),
        )
            ->where('device_id', $deviceId)
            ->where('id', '!=', $reservation->id)
            ->blocking()
            ->exists();
    }
}
