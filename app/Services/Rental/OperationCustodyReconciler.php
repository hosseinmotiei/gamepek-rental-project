<?php

namespace App\Services\Rental;

use App\Enums\CustodyActor;
use App\Enums\DeviceOwnership;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\GuaranteeNoteEvent;
use App\Models\Owner;
use App\Models\RentalApplication;
use App\Models\RentalDamageAssessment;
use App\Models\RentalDamagePayment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalSettlement;
use App\Models\Wallet;
use App\Models\WalletTransaction;
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

    /** The rental is Active (or later) but no delivery ever completed. */
    public const ACTIVE_WITHOUT_DELIVERY = 'active_without_delivery';

    /** The rental is Returned (or later) but no customer return completed. */
    public const RETURNED_WITHOUT_RETURN = 'returned_without_return';

    /** A delivery or return completed, but the rental never moved with it. */
    public const LIFECYCLE_BEHIND_OPERATION = 'lifecycle_behind_operation';

    /** A settlement credit whose wallet ledger entry is missing or wrong. */
    public const SETTLEMENT_CREDIT_WITHOUT_LEDGER = 'settlement_credit_without_ledger';

    /** Credited amount, ledger amount and owner share disagree. */
    public const SETTLEMENT_AMOUNT_MISMATCH = 'settlement_amount_mismatch';

    /** More than one wallet credit carries the same settlement reference. */
    public const DUPLICATE_OWNER_CREDIT = 'duplicate_owner_credit';

    /** A wallet credit exists for a settlement not marked credited. */
    public const LEDGER_CREDIT_WITHOUT_SETTLEMENT_RECORD = 'ledger_credit_without_settlement_record';

    /** A settlement for a rental that is not returned/closed, or not an owner device. */
    public const SETTLEMENT_FOR_INELIGIBLE_RENTAL = 'settlement_for_ineligible_rental';

    /** A Closed rental whose closure prerequisites are not all met. */
    public const CLOSED_WITHOUT_PREREQUISITES = 'closed_without_prerequisites';

    /** One promissory note both returned to the customer and given to the owner. */
    public const NOTE_RETURNED_AND_TRANSFERRED = 'note_returned_and_transferred';

    /** A damage payment for a different amount than its assessment. */
    public const DAMAGE_PAYMENT_MISMATCH = 'damage_payment_mismatch';

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
            ->merge($this->lifecycleFindings())
            ->merge($this->financeFindings())
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
            self::ACTIVE_WITHOUT_DELIVERY => 'اجاره فعال است اما تحویلی به مشتری تکمیل نشده است',
            self::RETURNED_WITHOUT_RETURN => 'اجاره بازگشت‌خورده است اما دریافتی از مشتری تکمیل نشده است',
            self::LIFECYCLE_BEHIND_OPERATION => 'عملیات تکمیل شده اما وضعیت اجاره همراه آن تغییر نکرده است',
            self::SETTLEMENT_CREDIT_WITHOUT_LEDGER => 'واریز تسویه ثبت شده اما سند کیف پول آن معتبر نیست',
            self::SETTLEMENT_AMOUNT_MISMATCH => 'مبلغ واریز تسویه با سهم مالک هم‌خوان نیست',
            self::DUPLICATE_OWNER_CREDIT => 'سهم مالک بیش از یک بار به کیف پول واریز شده است',
            self::LEDGER_CREDIT_WITHOUT_SETTLEMENT_RECORD => 'واریز به کیف پول مالک بدون ثبت در تسویه',
            self::SETTLEMENT_FOR_INELIGIBLE_RENTAL => 'تسویه برای اجاره‌ای که شرایط تسویه ندارد',
            self::CLOSED_WITHOUT_PREREQUISITES => 'اجاره بسته شده اما پیش‌نیازهای بستن کامل نیست',
            self::NOTE_RETURNED_AND_TRANSFERRED => 'سفته هم به مشتری بازگردانده و هم به مالک تحویل شده است',
            self::DAMAGE_PAYMENT_MISMATCH => 'مبلغ پرداخت خسارت با ارزیابی هم‌خوان نیست',
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

    /**
     * The rental's own lifecycle and the physical tasks that drive it must
     * agree. Delivery completion is the only producer of Active, return
     * completion the only producer of Returned, and both happen in the same
     * transaction as the handover -- so any disagreement means a row was
     * written outside the services. Reported, never repaired.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lifecycleFindings(): Collection
    {
        $postDelivery = [RentalApplicationState::Active, RentalApplicationState::Returned, RentalApplicationState::Closed];
        $postReturn = [RentalApplicationState::Returned, RentalApplicationState::Closed];

        $completed = RentalOperation::where('state', RentalOperationState::Completed->value)
            ->whereIn('type', [RentalOperationType::CustomerDelivery->value, RentalOperationType::CustomerReturn->value])
            ->get(['id', 'operation_number', 'rental_application_id', 'device_id', 'type']);

        $applications = RentalApplication::query()
            ->whereIn('state', array_map(fn ($s) => $s->value, $postDelivery))
            ->orWhereIn('id', $completed->pluck('rental_application_id'))
            ->get(['id', 'application_number', 'state'])
            ->keyBy('id');

        $findings = [];

        foreach ($applications as $application) {
            $types = $completed->where('rental_application_id', $application->id)->pluck('type');

            if (in_array($application->state, $postDelivery, true)
                && ! $types->contains(RentalOperationType::CustomerDelivery)) {
                $findings[] = $this->finding(self::ACTIVE_WITHOUT_DELIVERY, null, null,
                    'درخواست '.$application->application_number.' در وضعیت '.$application->state->label().' است اما تحویل تکمیل‌شده‌ای ندارد.');
            }

            if (in_array($application->state, $postReturn, true)
                && ! $types->contains(RentalOperationType::CustomerReturn)) {
                $findings[] = $this->finding(self::RETURNED_WITHOUT_RETURN, null, null,
                    'درخواست '.$application->application_number.' در وضعیت '.$application->state->label().' است اما دریافت تکمیل‌شده‌ای از مشتری ندارد.');
            }
        }

        foreach ($completed as $operation) {
            $application = $applications->get($operation->rental_application_id);
            $expected = $operation->type === RentalOperationType::CustomerDelivery ? $postDelivery : $postReturn;

            if ($application !== null && ! in_array($application->state, $expected, true)) {
                $findings[] = $this->finding(self::LIFECYCLE_BEHIND_OPERATION, $operation, null,
                    'عملیات تکمیل شده است اما درخواست '.$application->application_number.' هنوز در وضعیت '.$application->state->label().' است.');
            }
        }

        return collect($findings);
    }

    /**
     * Money, promissory notes and closure must agree with each other and with
     * the wallet ledger. Every check below is something the write path already
     * prevents; a finding means a row was written outside the services.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function financeFindings(): Collection
    {
        $findings = [];
        $add = function (string $code, string $detail) use (&$findings) {
            $findings[] = $this->finding($code, null, null, $detail);
        };

        foreach (RentalSettlement::with('credit')->get() as $settlement) {
            $ref = $settlement->reference_number;
            $application = RentalApplication::whereKey($settlement->rental_application_id)->first(['id', 'state']);
            $device = Device::whereKey($settlement->device_id)->first(['id', 'ownership', 'owner_id']);

            if (! in_array($application?->state, [RentalApplicationState::Returned, RentalApplicationState::Closed], true)
                || $device?->ownership !== DeviceOwnership::Owner
                || $device->owner_id !== $settlement->owner_id) {
                $add(self::SETTLEMENT_FOR_INELIGIBLE_RENTAL, 'تسویه '.$ref.' به اجاره یا دستگاه واجد شرایط تعلق ندارد.');
            }

            $ledger = WalletTransaction::where('context->settlement_reference', $ref)->get();

            if ($ledger->count() > 1) {
                $add(self::DUPLICATE_OWNER_CREDIT, 'برای تسویه '.$ref.' '.$ledger->count().' واریز ثبت شده است.');
            }

            $credit = $settlement->credit;

            if ($credit === null) {
                if ($ledger->isNotEmpty()) {
                    $add(self::LEDGER_CREDIT_WITHOUT_SETTLEMENT_RECORD, 'تسویه '.$ref.' واریز نشده ثبت شده اما در کیف پول واریز دارد.');
                }

                continue;
            }

            $entry = WalletTransaction::find($credit->wallet_transaction_id);
            $ownerUserId = Owner::whereKey($settlement->owner_id)->value('user_id');
            $walletUserId = $entry ? Wallet::whereKey($entry->wallet_id)->value('user_id') : null;

            if ($entry === null || $entry->type !== WalletTransaction::TYPE_CREDIT || $walletUserId !== $ownerUserId) {
                $add(self::SETTLEMENT_CREDIT_WITHOUT_LEDGER, 'واریز تسویه '.$ref.' به سند معتبری در کیف پول مالک اشاره نمی‌کند.');
            } elseif ($entry->amount !== $settlement->owner_share || $credit->amount !== $settlement->owner_share) {
                $add(self::SETTLEMENT_AMOUNT_MISMATCH, 'مبلغ واریز تسویه '.$ref.' با سهم مالک برابر نیست.');
            }
        }

        $readiness = app(RentalClosureReadiness::class);

        foreach (RentalApplication::where('state', RentalApplicationState::Closed->value)->get(['id', 'state', 'application_number']) as $application) {
            if (! $readiness->check($application)['ready']) {
                $add(self::CLOSED_WITHOUT_PREREQUISITES, 'درخواست '.$application->application_number.' بسته شده اما پیش‌نیازهای آن کامل نیست.');
            }
        }

        GuaranteeNoteEvent::whereNotNull('final_marker')
            ->get(['guarantee_id', 'event'])
            ->groupBy('guarantee_id')
            ->filter(fn ($events) => $events->pluck('event')->unique()->count() > 1)
            ->each(fn ($events, $guaranteeId) => $add(self::NOTE_RETURNED_AND_TRANSFERRED, 'سفته ضمانت شماره '.$guaranteeId.' دو سرنوشت متناقض دارد.'));

        foreach (RentalDamagePayment::all() as $payment) {
            if (RentalDamageAssessment::whereKey($payment->rental_damage_assessment_id)->value('amount') !== $payment->amount) {
                $add(self::DAMAGE_PAYMENT_MISMATCH, 'پرداخت خسارت شماره '.$payment->id.' با مبلغ ارزیابی برابر نیست.');
            }
        }

        return collect($findings);
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
