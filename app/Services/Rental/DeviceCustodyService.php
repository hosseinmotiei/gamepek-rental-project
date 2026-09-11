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
 *  - No customer -> owner leg. The device always passes back through GamePek;
 *    every leg refuses unless its source side actually holds the device, so
 *    an owner return is impossible while a customer still has it.
 *
 *  - No legal claim. `acknowledged` is the counterparty confirming GamePek's
 *    record. It is not a signature, not acceptance, and says nothing about the
 *    condition of the device. Whether a digital confirmation may stand in for
 *    the paper receipt/signature is not decided (CONFIRMED_DECISIONS.md §4.2).
 *    Condition evidence beyond the handover note lives in RentalInspection.
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
     * GamePek records handing the device to the customer at their door.
     *
     * CONFIRMED RULE: this is the event that starts a rental. Completing the
     * delivery task moves the application Approved -> Active through
     * RentalOperationService::completeAfterCustody(), which asks the
     * orchestrator -- nothing here writes an application state.
     *
     * The device must actually be in GamePek custody first: you cannot hand
     * over what you are not holding.
     *
     * `$diagnosisNotes` carries the condition check performed at the door
     * (confirmed rule). It is free text on purpose -- no damage taxonomy,
     * severity scale or pricing is defined, and none is invented here.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function requestDeliveryToCustomer(RentalOperation $operation, User $actor): DeviceCustodyTransfer
    {
        return $this->openTransfer($operation, RentalOperationType::CustomerDelivery, $actor);
    }

    public function recordDeliveryToCustomer(
        RentalOperation $operation,
        User $actor,
        ?string $diagnosisNotes = null,
    ): DeviceCustodyTransfer {
        return $this->recordPossessionMove(
            $operation,
            RentalOperationType::CustomerDelivery,
            $actor,
            $diagnosisNotes,
        );
    }

    /**
     * GamePek records taking the device back from the customer.
     *
     * CONFIRMED RULE: the return is coordinated through support, so it is
     * recorded by staff. Completing the task moves Active -> Returned through
     * the orchestrator.
     *
     * The moment recorded here is also the start of the owner's two-hour
     * defect-report window -- see
     * DeviceCustodyTransfer::ownerDefectReportDeadline().
     *
     * @throws \RuntimeException with a Persian message
     */
    public function requestReturnFromCustomer(RentalOperation $operation, User $actor): DeviceCustodyTransfer
    {
        return $this->openTransfer($operation, RentalOperationType::CustomerReturn, $actor);
    }

    public function recordReturnToGamePek(
        RentalOperation $operation,
        User $actor,
        ?string $diagnosisNotes = null,
    ): DeviceCustodyTransfer {
        return $this->recordPossessionMove(
            $operation,
            RentalOperationType::CustomerReturn,
            $actor,
            $diagnosisNotes,
        );
    }

    /**
     * GamePek hands a returned console back to its owner (C-40).
     *
     * The final leg. Refused unless the device is owner-owned, is in GamePek
     * custody, and got there through THIS rental's customer return -- so a
     * console still with a customer can never be recorded as going straight
     * to its owner, and one physical return cannot produce two owner returns
     * across two rentals. Changes no application state: closure is undecided.
     *
     * No owner acceptance wording or signature is implied. The owner may
     * confirm the record afterwards through the same acknowledgement as the
     * pickup, which carries no legal weight.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function requestReturnToOwner(RentalOperation $operation, User $actor): DeviceCustodyTransfer
    {
        return $this->openTransfer($operation, RentalOperationType::OwnerReturn, $actor);
    }

    public function recordReturnToOwner(
        RentalOperation $operation,
        User $actor,
        ?string $notes = null,
    ): DeviceCustodyTransfer {
        return $this->recordPossessionMove($operation, RentalOperationType::OwnerReturn, $actor, $notes);
    }

    /**
     * The customer confirms GamePek's record of a handover they were party to.
     *
     * Exactly the same meaning as the owner's acknowledgement: a confirmation
     * that the RECORD matches what happened. It is NOT a signature, NOT legal
     * acceptance, and says nothing about the condition of the device. The
     * confirmed requirement that a delivery carries a receipt and a customer
     * signature is satisfied operationally at the door and referenced by
     * `reference_number`; whether a digital signature may replace the physical
     * one is not decided and nothing here claims it does.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function acknowledgeByCustomer(DeviceCustodyTransfer $transfer, User $actor): DeviceCustodyTransfer
    {
        $transfer->loadMissing('operation.application');
        $application = $transfer->operation?->application;

        // Ownership of the rental is the authority here, never a posted id.
        if ($application === null || $application->user_id !== $actor->id) {
            throw new \RuntimeException('این سابقه تحویل به درخواست اجاره شما مربوط نیست.');
        }

        if (! in_array($transfer->transfer_type, [
            CustodyTransferType::GamePekToCustomer,
            CustodyTransferType::CustomerToGamePek,
        ], true)) {
            throw new \RuntimeException('مشتری طرف این انتقال تحویل نیست.');
        }

        return $this->acknowledge($transfer, $actor);
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
        // An owner may only confirm a leg they were actually a party to --
        // the pickup from them or the return to them. Without this, an owner
        // reaching a delivery or return record would be confirming a handover
        // between GamePek and the customer.
        if (! $transfer->transfer_type->involvesOwner()) {
            throw new \RuntimeException('مالک طرف این انتقال تحویل نیست.');
        }

        // And it must be THEIR side of it. RentalOperationPolicy already
        // checks this at the route; it is re-checked here against the owner
        // reference on the transfer itself, so no future caller can skip it.
        $ownerId = $transfer->transfer_type->source() === CustodyActor::Owner
            ? $transfer->from_owner_id
            : $transfer->to_owner_id;

        if ($ownerId === null || $actor->owner?->id !== $ownerId) {
            throw new \RuntimeException('این سابقه تحویل به دستگاه شما مربوط نیست.');
        }

        return $this->acknowledge($transfer, $actor);
    }

    /**
     * The shared acknowledgement write. Both counterparties reach it only
     * after their own authorization check above.
     */
    private function acknowledge(DeviceCustodyTransfer $transfer, User $actor): DeviceCustodyTransfer
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
     * Open the paperwork for a delivery or a return. NOT possession.
     *
     * Idempotent: an existing transfer for the task is returned untouched, and
     * unique(rental_operation_id) catches the concurrent case that check
     * cannot -- the same shape requestFromOwner() uses for the owner leg.
     */
    private function openTransfer(
        RentalOperation $operation,
        RentalOperationType $expectedType,
        User $actor,
    ): DeviceCustodyTransfer {
        return DB::transaction(function () use ($operation, $expectedType, $actor) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            $existing = $locked->custodyTransfer()->first();

            if ($existing) {
                return $existing;
            }

            $device = $this->assertCustodyPreconditions($locked, $expectedType);
            $type = $expectedType->custodyTransferType();

            try {
                // Assigned attribute-by-attribute, never mass-assigned: the
                // model guards everything, so actors and state cannot arrive
                // from request data even by accident.
                $transfer = new DeviceCustodyTransfer;
                $transfer->reference_number = DeviceCustodyTransfer::generateReference();
                $transfer->device_id = $device->id;
                $transfer->rental_operation_id = $locked->id;
                $transfer->rental_reservation_id = $locked->rental_reservation_id;
                $transfer->transfer_type = $type;

                // Derived from the type, never chosen independently.
                $transfer->from_actor_type = $type->source();
                $transfer->to_actor_type = $type->destination();

                // Only an owner side names an owner. Neither side of a
                // delivery or a return is one, so both stay null and the
                // owner-reference CHECK constraint is satisfied.
                $transfer->from_owner_id = $type->source() === CustodyActor::Owner ? $device->owner_id : null;
                $transfer->to_owner_id = $type->destination() === CustodyActor::Owner ? $device->owner_id : null;

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
     * Record that possession actually moved, and close the task behind it.
     *
     * Ownership is not touched and that is asserted afterwards rather than
     * trusted, exactly as the owner leg does.
     *
     * Concurrency: the operation row is locked first, so two operators
     * recording the same handover serialise here. The loser finds the transfer
     * already moved and gets a Persian conflict message, not a second record --
     * and unique(rental_operation_id) backs that up in the database.
     */
    private function recordPossessionMove(
        RentalOperation $operation,
        RentalOperationType $expectedType,
        User $actor,
        ?string $notes,
    ): DeviceCustodyTransfer {
        return DB::transaction(function () use ($operation, $expectedType, $actor, $notes) {
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== RentalOperationState::InProgress) {
                throw new \RuntimeException('این عملیات در وضعیت لازم برای ثبت تحویل نیست.');
            }

            $device = $this->assertCustodyPreconditions($locked, $expectedType);

            $transfer = $locked->custodyTransfer()->lockForUpdate()->first()
                ?? throw new \RuntimeException('برای این عملیات درخواست تحویلی ثبت نشده است.');

            if ($transfer->isPossessionMoved()) {
                throw new \RuntimeException('تحویل این دستگاه قبلاً ثبت شده است.');
            }

            if (! $transfer->state->canTransitionTo(CustodyTransferState::Transferred)) {
                throw new \RuntimeException('وضعیت تحویل این دستگاه اجازه این تغییر را نمی‌دهد.');
            }

            if ($transfer->device_id !== $locked->device_id) {
                throw new \RuntimeException('دستگاه ثبت‌شده در سابقه تحویل با دستگاه این عملیات یکسان نیست.');
            }

            if ($transfer->transfer_type !== $expectedType->custodyTransferType()) {
                throw new \RuntimeException('نوع سابقه تحویل با نوع این عملیات یکسان نیست.');
            }

            $this->assertActorsMatchType($transfer);

            $ownershipBefore = [$device->owner_id, $device->ownership->value];

            $transfer->state = CustodyTransferState::Transferred;
            $transfer->transferred_at = now();
            $transfer->transferred_by_user_id = $actor->id;

            // The condition check performed at the door (confirmed rule).
            // Free text: no damage taxonomy or severity scale is defined.
            if ($notes !== null && trim($notes) !== '') {
                $transfer->notes = mb_substr(trim($notes), 0, 2000);
            }

            $transfer->save();

            $this->audit('custody.transferred', $transfer, $device, $actor);

            // Closing the task is what asks the orchestrator to move the
            // rental's own lifecycle -- see
            // RentalOperationService::completeAfterCustody().
            $this->operations->completeAfterCustody($locked, $actor);

            $this->assertOwnershipUnchanged($device, $ownershipBefore);

            return $transfer;
        });
    }

    /**
     * Everything that must be true before a delivery or return may be
     * recorded, checked here rather than at the call sites so no future
     * controller can skip one.
     *
     * The last check is the one that matters most: the side giving the device
     * up must actually be holding it. You cannot deliver a console you never
     * collected, and you cannot take one back from a customer who never
     * received it.
     */
    private function assertCustodyPreconditions(
        RentalOperation $operation,
        RentalOperationType $expectedType,
    ): Device {
        if ($operation->type !== $expectedType) {
            throw new \RuntimeException('نوع این عملیات با ثبت تحویل درخواستی یکسان نیست.');
        }

        $device = $operation->device()->first();

        if ($device === null) {
            throw new \RuntimeException('تا زمانی که دستگاه مشخص نشده باشد، تحویل قابل ثبت نیست.');
        }

        $reservation = $operation->reservation()->first();

        if ($reservation === null || $reservation->device_id !== $device->id) {
            throw new \RuntimeException('دستگاه این عملیات با رزرو مربوطه هم‌خوان نیست.');
        }

        if ($device->product_id !== $reservation->product_id) {
            throw new \RuntimeException('این دستگاه از مدل رزروشده نیست.');
        }

        if ($device->currentCustody() !== $expectedType->custodyTransferType()->source()) {
            throw new \RuntimeException('این دستگاه در اختیار طرفی نیست که باید آن را تحویل دهد.');
        }

        // Holding the device is not enough -- it must be held BECAUSE OF THIS
        // RENTAL. A customer may only hand back what this rental delivered to
        // them, and an owner may only receive what this rental's customer
        // returned. Without this, one rental's task could record evidence
        // about another rental's handover of the same console.
        match ($expectedType) {
            RentalOperationType::CustomerReturn => $this->assertLatestMovementIs(
                $device,
                $operation,
                CustodyTransferType::GamePekToCustomer,
                'این دستگاه در اجرای همین اجاره به مشتری تحویل نشده است.',
            ),
            RentalOperationType::OwnerReturn => $this->assertOwnerReturnable($device, $operation),
            default => null,
        };

        return $device;
    }

    private function assertOwnerReturnable(Device $device, RentalOperation $operation): void
    {
        if ($device->ownership !== DeviceOwnership::Owner || $device->owner_id === null) {
            throw new \RuntimeException('این دستگاه متعلق به گیم‌پک است و بازگرداندن به مالک ندارد.');
        }

        if ($operation->owner_id !== $device->owner_id) {
            throw new \RuntimeException('مالک ثبت‌شده برای این عملیات با مالک دستگاه یکسان نیست.');
        }

        $this->assertLatestMovementIs(
            $device,
            $operation,
            CustodyTransferType::CustomerToGamePek,
            'این دستگاه در اجرای همین اجاره از مشتری دریافت نشده است.',
        );

        // A later rental may already be counting on GamePek holding this
        // console: its pickup resolved to `not_required` for exactly that
        // reason, and it has not been delivered yet. Handing the device back
        // to the owner now would leave that rental with a closed pickup and no
        // device to deliver. Whether it should instead go home first is an
        // allocation decision for a human; this only refuses the silent
        // contradiction.
        if ($this->operations->isDeviceHeldForAnotherRental($device->id, $operation->rental_reservation_id)) {
            throw new \RuntimeException('این دستگاه برای اجاره دیگری در اختیار گیم‌پک نگه داشته شده است و نمی‌توان آن را به مالک بازگرداند.');
        }
    }

    /**
     * The device's most recent actual movement must be $expected, recorded
     * for this operation's own reservation.
     */
    private function assertLatestMovementIs(
        Device $device,
        RentalOperation $operation,
        CustodyTransferType $expected,
        string $message,
    ): void {
        $latest = DeviceCustodyTransfer::where('device_id', $device->id)
            ->possessionMoved()
            ->latest('id')
            ->first();

        if ($latest === null
            || $latest->transfer_type !== $expected
            || $latest->rental_reservation_id !== $operation->rental_reservation_id) {
            throw new \RuntimeException($message);
        }
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

        // You can only collect a device from an owner who has it. Once the
        // lifecycle is circular, an owner's console may still be with GamePek
        // (kept between rentals) or with another customer; recording an
        // owner -> GamePek handover then would invent a handover nobody made.
        // attachDevice() resolves the GamePek-custody case to `not_required`
        // up front, so this is the backstop.
        if ($device->currentCustody() !== CustodyActor::Owner) {
            throw new \RuntimeException('این دستگاه اکنون در اختیار مالک نیست و تحویل گرفتن از مالک برای آن ممکن نیست.');
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
