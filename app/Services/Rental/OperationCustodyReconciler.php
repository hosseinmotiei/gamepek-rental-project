<?php

namespace App\Services\Rental;

use App\Enums\CustodyActor;
use App\Enums\DeviceOwnership;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\DeviceCustodyTransfer;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use Illuminate\Support\Collection;

/**
 * Detects operations and custody records that have drifted into contradiction.
 *
 * WHY THIS EXISTS
 *
 * Every finding below is something the write path already prevents. That is
 * exactly why the reconciler is worth having: a guard that has never fired
 * tells you nothing, and the failure mode of a custody system is silence.
 * Records reach this state through the paths no service controls -- a console
 * command, a data import, a hand-run UPDATE during an incident, a future bug,
 * or rows written before a guard existed.
 *
 * READ-ONLY, ON PURPOSE. It repairs nothing and decides nothing.
 *
 * That restraint is deliberate. Every plausible repair is an undecided business
 * question: whether a completed pickup with no handover should be reopened or
 * the handover backfilled, whether a device found in the wrong custody should
 * be corrected toward the record or the record toward reality, who is liable
 * meanwhile. Guessing would destroy the evidence of whatever produced the
 * contradiction, which is the only thing that makes it fixable by a human.
 *
 * So it reports, and a person decides.
 */
class OperationCustodyReconciler
{
    /** Operation completed, but no handover was ever recorded. */
    public const COMPLETED_WITHOUT_TRANSFER = 'completed_without_transfer';

    /** Operation completed, but no device is named. */
    public const COMPLETED_WITHOUT_DEVICE = 'completed_without_device';

    /** Operation completed on an owner device that is not in GamePek custody. */
    public const COMPLETED_BUT_CUSTODY_NOT_GAMEPEK = 'completed_but_custody_not_gamepek';

    /** Delivery completed, but the device is not in the customer's hands. */
    public const COMPLETED_BUT_CUSTODY_NOT_CUSTOMER = 'completed_but_custody_not_customer';

    /** Owner return completed, but the device is not back with its owner. */
    public const COMPLETED_BUT_CUSTODY_NOT_OWNER = 'completed_but_custody_not_owner';

    /** An inspection whose references disagree with its operation or handover. */
    public const INSPECTION_REFERENCE_MISMATCH = 'inspection_reference_mismatch';

    /** The completed task and its transfer describe different legs. */
    public const TRANSFER_TYPE_MISMATCH = 'transfer_type_mismatch';

    /** The operation and its transfer name different consoles. */
    public const DEVICE_MISMATCH = 'device_mismatch';

    /** Possession moved, but the operation never closed. */
    public const TRANSFERRED_BUT_NOT_COMPLETED = 'transferred_but_not_completed';

    /** A transfer whose declared type and actual actors disagree. */
    public const ACTOR_PAIR_MISMATCH = 'actor_pair_mismatch';

    /**
     * Every contradiction currently visible in the data.
     *
     * @return Collection<int, array{code: string, label: string, operation_id: int|null,
     *                               operation_number: string|null, device_id: int|null,
     *                               transfer_reference: string|null, detail: string}>
     */
    public function findings(): Collection
    {
        return collect()
            ->merge($this->completedOperationFindings())
            ->merge($this->transferFindings())
            ->merge($this->inspectionFindings())
            // A device mismatch is visible from both sides; report it once.
            ->unique(fn (array $f) => $f['code'].'|'.$f['operation_id'].'|'.$f['transfer_reference'])
            ->values();
    }

    public function hasFindings(): bool
    {
        return $this->findings()->isNotEmpty();
    }

    public function label(string $code): string
    {
        return match ($code) {
            self::COMPLETED_WITHOUT_TRANSFER => 'عملیات تکمیل شده اما تحویلی ثبت نشده است',
            self::COMPLETED_WITHOUT_DEVICE => 'عملیات تکمیل شده اما دستگاهی مشخص نیست',
            self::COMPLETED_BUT_CUSTODY_NOT_GAMEPEK => 'عملیات تکمیل شده اما دستگاه در اختیار گیم‌پک نیست',
            self::COMPLETED_BUT_CUSTODY_NOT_CUSTOMER => 'تحویل تکمیل شده اما دستگاه در اختیار مشتری نیست',
            self::COMPLETED_BUT_CUSTODY_NOT_OWNER => 'بازگرداندن به مالک تکمیل شده اما دستگاه در اختیار مالک نیست',
            self::INSPECTION_REFERENCE_MISMATCH => 'ارجاعات بازرسی با عملیات یا سابقه تحویل آن هم‌خوان نیست',
            self::TRANSFER_TYPE_MISMATCH => 'نوع سابقه تحویل با نوع عملیات یکسان نیست',
            self::DEVICE_MISMATCH => 'دستگاه عملیات با دستگاه سابقه تحویل یکسان نیست',
            self::TRANSFERRED_BUT_NOT_COMPLETED => 'تحویل ثبت شده اما عملیات بسته نشده است',
            self::ACTOR_PAIR_MISMATCH => 'طرفین انتقال با نوع آن هم‌خوان نیستند',
            default => $code,
        };
    }

    /** @return Collection<int, array<string, mixed>> */
    private function completedOperationFindings(): Collection
    {
        $operations = RentalOperation::with(['device', 'custodyTransfer'])
            ->where('state', RentalOperationState::Completed->value)
            ->get();

        return $operations->flatMap(function (RentalOperation $operation) {
            $findings = [];
            $device = $operation->device;
            $transfer = $operation->custodyTransfer;

            if ($device === null) {
                // A CHECK constraint forbids this; a legacy row could still
                // carry it, so it is reported rather than assumed impossible.
                $findings[] = $this->finding(
                    self::COMPLETED_WITHOUT_DEVICE,
                    $operation,
                    null,
                    'عملیات در وضعیت تکمیل‌شده است اما هیچ دستگاهی به آن متصل نیست.'
                );

                return $findings;
            }

            // A GamePek-owned device's PICKUP closes as `not_required` and
            // never as `completed`, so a completed pickup means a handover was
            // claimed. Delivery and return always involve a real handover,
            // whoever owns the console.
            $expectsHandover = $operation->type === RentalOperationType::OwnerDevicePickup
                ? $device->ownership === DeviceOwnership::Owner
                : true;

            $leg = $operation->type->custodyTransferType();

            if ($expectsHandover && ($transfer === null || ! $transfer->isPossessionMoved())) {
                $findings[] = $this->finding(
                    self::COMPLETED_WITHOUT_TRANSFER,
                    $operation,
                    $transfer,
                    'عملیات تکمیل شده است اما انتقال تحویل مربوط به آن ثبت نشده است.'
                );
            }

            // Where the device must be once this leg has completed -- but only
            // while this is still the device's MOST RECENT movement. A
            // collected console that has since been delivered to the customer
            // is not a contradiction: the pickup's expectation was satisfied
            // and then legitimately superseded. Without this, every completed
            // pickup would be reported the moment its rental started.
            $supersededByLaterLeg = DeviceCustodyTransfer::where('device_id', $device->id)
                ->possessionMoved()
                ->when($transfer !== null, fn ($q) => $q->where('id', '>', $transfer->id))
                ->exists();

            if ($expectsHandover && ! $supersededByLaterLeg && $device->currentCustody() !== $leg->destination()) {
                $findings[] = $this->finding(
                    match ($leg->destination()) {
                        CustodyActor::Customer => self::COMPLETED_BUT_CUSTODY_NOT_CUSTOMER,
                        CustodyActor::Owner => self::COMPLETED_BUT_CUSTODY_NOT_OWNER,
                        CustodyActor::GamePek => self::COMPLETED_BUT_CUSTODY_NOT_GAMEPEK,
                    },
                    $operation,
                    $transfer,
                    'دستگاه پس از تکمیل این عملیات باید در اختیار '
                        .$leg->destination()->label().' باشد اما نیست.'
                );
            }

            if ($transfer !== null && $transfer->device_id !== $operation->device_id) {
                $findings[] = $this->finding(
                    self::DEVICE_MISMATCH,
                    $operation,
                    $transfer,
                    'شناسه دستگاه در عملیات و در سابقه تحویل یکسان نیست.'
                );
            }

            // The task and its handover must describe the same leg: a delivery
            // task carrying an owner-pickup transfer would mean the wrong two
            // parties were recorded as exchanging the device.
            if ($transfer !== null && $transfer->transfer_type !== $leg) {
                $findings[] = $this->finding(
                    self::TRANSFER_TYPE_MISMATCH,
                    $operation,
                    $transfer,
                    'نوع سابقه تحویل ('.$transfer->transfer_type->label()
                        .') با نوع عملیات ('.$operation->type->label().') یکسان نیست.'
                );
            }

            return $findings;
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function transferFindings(): Collection
    {
        $transfers = DeviceCustodyTransfer::with('operation')->get();

        return $transfers->flatMap(function (DeviceCustodyTransfer $transfer) {
            $findings = [];
            $operation = $transfer->operation;

            if (! $transfer->actorsMatchType()) {
                $findings[] = $this->finding(
                    self::ACTOR_PAIR_MISMATCH,
                    $operation,
                    $transfer,
                    'نوع انتقال با طرفین ثبت‌شده هم‌خوان نیست.'
                );
            }

            if ($transfer->isPossessionMoved()
                && $operation !== null
                && $operation->state !== RentalOperationState::Completed) {
                $findings[] = $this->finding(
                    self::TRANSFERRED_BUT_NOT_COMPLETED,
                    $operation,
                    $transfer,
                    'تحویل فیزیکی ثبت شده است اما عملیات مربوطه در وضعیت '
                        .$operation->state->label().' مانده است.'
                );
            }

            if ($operation !== null && $transfer->device_id !== $operation->device_id) {
                $findings[] = $this->finding(
                    self::DEVICE_MISMATCH,
                    $operation,
                    $transfer,
                    'شناسه دستگاه در سابقه تحویل با عملیات یکسان نیست.'
                );
            }

            return $findings;
        });
    }

    /**
     * Every inspection must describe the same rental, console and handover as
     * the operation it hangs off, at the stage that operation implies.
     * RentalInspectionService derives all of these; a mismatch means a row was
     * written or altered outside it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function inspectionFindings(): Collection
    {
        $inspections = RentalInspection::with(['operation.custodyTransfer'])->get();

        return $inspections->flatMap(function (RentalInspection $inspection) {
            $operation = $inspection->operation;
            $transfer = $operation?->custodyTransfer;

            $consistent = $operation !== null
                && $transfer !== null
                && $inspection->device_custody_transfer_id === $transfer->id
                && $inspection->device_id === $operation->device_id
                && $inspection->device_id === $transfer->device_id
                && $inspection->rental_reservation_id === $operation->rental_reservation_id
                && $inspection->rental_application_id === $operation->rental_application_id
                && $inspection->stage === $operation->type->inspectionStage();

            if ($consistent) {
                return [];
            }

            return [$this->finding(
                self::INSPECTION_REFERENCE_MISMATCH,
                $operation,
                $transfer,
                'بازرسی شماره '.$inspection->id.' به دستگاه، رزرو، سابقه تحویل یا مرحله‌ای غیر از عملیات خود اشاره می‌کند.'
            )];
        });
    }

    /** @return array<string, mixed> */
    private function finding(
        string $code,
        ?RentalOperation $operation,
        ?DeviceCustodyTransfer $transfer,
        string $detail,
    ): array {
        return [
            'code' => $code,
            'label' => $this->label($code),
            'operation_id' => $operation?->id,
            'operation_number' => $operation?->operation_number,
            'device_id' => $operation?->device_id ?? $transfer?->device_id,
            'transfer_reference' => $transfer?->reference_number,
            'detail' => $detail,
        ];
    }
}
