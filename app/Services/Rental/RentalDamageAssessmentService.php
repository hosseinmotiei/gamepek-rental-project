<?php

namespace App\Services\Rental;

use App\Enums\RentalApplicationState;
use App\Enums\RentalInspectionStage;
use App\Models\GuaranteeNoteEvent;
use App\Models\RentalApplication;
use App\Models\RentalDamageAssessment;
use App\Models\RentalDamagePayment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `rental_damage_assessments` and `rental_damage_payments`.
 *
 * CONFIRMED (C-39 and the promissory-note rules):
 *  - GamePek's expert determines the damage amount, on the return inspection;
 *    an amount of 0 is the expert recording that there is no damage;
 *  - the customer may pay the assessed amount directly; that payment is
 *    recorded explicitly, once, for exactly the assessed amount.
 *
 * The LATEST assessment is the current one (earlier rows are revisions).
 * Revising stops once the damage is paid or the note has been resolved, so a
 * paid or transferred obligation can never be silently re-priced.
 *
 * CONFIRMED since: a paid damage is credited IN FULL to GamePek's wallet
 * (WalletService::creditGamePek), separate from the owner's 35/65 settlement.
 * Staff decide whether the customer paid; there is no deadline. A note RETAINED
 * by GamePek (a GamePek-owned device, no owner to hand it to) does not close
 * the door on that payment -- see recordPayment().
 *
 * NOT DONE HERE: no formula, no category, no gateway integration.
 */
class RentalDamageAssessmentService
{
    public const NOT_ASSESSED = 'not_assessed';

    public const NO_DAMAGE = 'no_damage';

    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

    public function __construct(private WalletService $wallets) {}

    /**
     * @throws \RuntimeException with a Persian message
     */
    public function record(
        RentalInspection $inspection,
        User $actor,
        int $amount,
        ?string $notes = null,
        ?string $evidenceReference = null,
    ): RentalDamageAssessment {
        if ($amount < 0) {
            throw new \RuntimeException('مبلغ خسارت نمی‌تواند منفی باشد.');
        }

        return DB::transaction(function () use ($inspection, $actor, $amount, $notes, $evidenceReference) {
            // Serialises with inspection, handover and payment writes.
            $operation = RentalOperation::where('id', $inspection->rental_operation_id)->lockForUpdate()->firstOrFail();
            $inspection = RentalInspection::where('id', $inspection->id)->firstOrFail();

            if ($inspection->stage !== RentalInspectionStage::CustomerReturn) {
                throw new \RuntimeException('ارزیابی خسارت فقط برای بازرسی دستگاه بازگشته از مشتری ثبت می‌شود.');
            }

            if ($inspection->device_id !== $operation->device_id
                || $inspection->rental_reservation_id !== $operation->rental_reservation_id
                || $inspection->rental_application_id !== $operation->rental_application_id) {
                throw new \RuntimeException('ارجاعات این بازرسی با عملیات آن هم‌خوان نیست.');
            }

            if ($this->isSettled($inspection->rental_application_id)) {
                throw new \RuntimeException('خسارت این اجاره پرداخت یا تعیین تکلیف شده است و قابل بازبینی نیست.');
            }

            $assessment = new RentalDamageAssessment;
            $assessment->rental_inspection_id = $inspection->id;
            $assessment->rental_operation_id = $operation->id;
            $assessment->rental_reservation_id = $inspection->rental_reservation_id;
            $assessment->rental_application_id = $inspection->rental_application_id;
            $assessment->device_id = $inspection->device_id;
            $assessment->amount = $amount;
            $assessment->notes = $this->clean($notes, 2000);
            $assessment->evidence_reference = $this->clean($evidenceReference, 255);
            $assessment->assessed_by_user_id = $actor->id;
            $assessment->assessed_at = now();
            $assessment->created_at = now();
            $assessment->save();

            AuditLogger::log(
                action: 'damage_assessment.recorded',
                resourceType: 'RentalDamageAssessment',
                resourceId: $assessment->id,
                context: [
                    'rental_inspection_id' => $inspection->id,
                    'rental_application_id' => $assessment->rental_application_id,
                    'device_id' => $assessment->device_id,
                    'amount' => $amount,
                ],
                actor: $actor,
            );

            return $assessment;
        });
    }

    /**
     * Record that the customer paid the CURRENT assessed amount directly.
     *
     * The amount is copied from the assessment, never taken from a request.
     * Idempotent: a second call returns the first payment.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function recordPayment(RentalApplication $application, User $actor, string $paymentReference): RentalDamagePayment
    {
        $paymentReference = trim($paymentReference);

        if ($paymentReference === '') {
            throw new \RuntimeException('ثبت شناسه پرداخت الزامی است.');
        }

        return DB::transaction(function () use ($application, $actor, $paymentReference) {
            $current = $this->currentAssessment($application->id)
                ?? throw new \RuntimeException('برای این اجاره ارزیابی خسارتی ثبت نشده است.');

            // Same lock as record(): a revision and a payment cannot interleave.
            RentalOperation::where('id', $current->rental_operation_id)->lockForUpdate()->firstOrFail();
            $current = $this->currentAssessment($application->id);

            $existing = RentalDamagePayment::where('rental_damage_assessment_id', $current->id)->first();

            if ($existing) {
                return $existing;
            }

            // CONFIRMED (C-59): closing a rental does not extinguish an unpaid
            // damage. A rental can be closed with the note retained precisely
            // BECAUSE the damage is outstanding, so the customer must still be
            // able to settle it afterwards. Closed is accepted for that reason
            // alone -- this records a payment and NOTHING else: the lifecycle
            // state is not touched here (the orchestrator remains its only
            // writer), no operation or custody transfer is created, and no
            // settlement is recalculated.
            //
            // Every other state is still refused: the device must be back
            // before a return-inspection damage can be paid, and a cancelled or
            // rejected rental has no confirmed rule at all (C-52).
            $state = RentalApplication::whereKey($application->id)->firstOrFail(['id', 'state'])->state;

            if (! in_array($state, [RentalApplicationState::Returned, RentalApplicationState::Closed], true)) {
                throw new \RuntimeException('خسارت فقط برای اجاره‌ای که دستگاه آن بازگشته است پرداخت می‌شود.');
            }

            $reservation = RentalReservation::where('rental_application_id', $application->id)->first();

            if ($reservation === null
                || $current->rental_reservation_id !== $reservation->id
                || $current->device_id !== $reservation->device_id) {
                throw new \RuntimeException('ارتباط ارزیابی خسارت با اجاره یا دستگاه آن معتبر نیست.');
            }

            if ($current->amount === 0) {
                throw new \RuntimeException('برای این اجاره خسارتی تعیین نشده است که پرداخت شود.');
            }

            // CONFIRMED: only a note that has LEFT GamePek closes this door.
            // Handed to the owner, the debt is the owner's to pursue and
            // GamePek no longer collects it; returned to the customer, there
            // was nothing left to pay. A note RETAINED by GamePek (a GamePek-
            // owned device, with no owner to hand it to) is the opposite case:
            // GamePek still holds it precisely because the damage is unpaid,
            // and the customer may pay it later -- after which the note goes
            // back to them.
            if ($this->noteHasLeftGamePek($application->id)) {
                throw new \RuntimeException('سفته این اجاره تعیین تکلیف شده است و پرداخت خسارت دیگر از طریق گیم‌پک ثبت نمی‌شود.');
            }

            // CONFIRMED: paid damage is GamePek's receipt, credited to the
            // GamePek wallet in full -- never split 35/65, never touching the
            // owner's settlement. The key is derived from the assessment, so
            // a replay can never credit twice.
            $entry = $this->wallets->creditGamePek(
                $current->amount,
                'rental_damage_payment',
                'damage:'.$current->id.':gamepek',
                [
                    'rental_application_id' => $application->id,
                    'rental_damage_assessment_id' => $current->id,
                    'payment_reference' => mb_substr($paymentReference, 0, 100),
                ],
                $actor,
            );

            $payment = new RentalDamagePayment;
            $payment->rental_damage_assessment_id = $current->id;
            $payment->rental_application_id = $application->id;
            $payment->amount = $current->amount;
            $payment->wallet_transaction_id = $entry->id;
            $payment->payment_reference = mb_substr($paymentReference, 0, 100);
            $payment->recorded_by_user_id = $actor->id;
            $payment->paid_at = now();
            $payment->created_at = now();
            $payment->save();

            AuditLogger::log(
                action: 'damage_payment.recorded',
                resourceType: 'RentalDamagePayment',
                resourceId: $payment->id,
                context: [
                    'rental_application_id' => $application->id,
                    'rental_damage_assessment_id' => $current->id,
                    'amount' => $payment->amount,
                    'payment_reference' => $payment->payment_reference,
                ],
                actor: $actor,
            );

            return $payment;
        });
    }

    /**
     * The damage position of a rental, read-only.
     *
     * @return array{status: string, assessment: ?RentalDamageAssessment, payment: ?RentalDamagePayment}
     */
    public function statusFor(int $applicationId): array
    {
        $assessment = $this->currentAssessment($applicationId);
        $payment = $assessment === null ? null
            : RentalDamagePayment::where('rental_damage_assessment_id', $assessment->id)->first();

        $status = match (true) {
            $assessment === null => self::NOT_ASSESSED,
            $assessment->amount === 0 => self::NO_DAMAGE,
            $payment !== null => self::PAID,
            default => self::UNPAID,
        };

        return compact('status', 'assessment', 'payment');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::NOT_ASSESSED => 'هنوز ارزیابی نشده',
            self::NO_DAMAGE => 'بدون خسارت',
            self::UNPAID => 'پرداخت نشده',
            self::PAID => 'پرداخت شده',
            default => $status,
        };
    }

    private function currentAssessment(int $applicationId): ?RentalDamageAssessment
    {
        return RentalDamageAssessment::where('rental_application_id', $applicationId)->latest('id')->first();
    }

    /**
     * Re-pricing stops as soon as the obligation has been acted on: paid, or
     * the note given an outcome -- retention included. A retained note is a
     * recorded decision about a specific amount, so that amount must stay the
     * amount the customer can still pay.
     */
    private function isSettled(int $applicationId): bool
    {
        return RentalDamagePayment::where('rental_application_id', $applicationId)->exists()
            || GuaranteeNoteEvent::where('rental_application_id', $applicationId)
                ->whereIn('event', [
                    GuaranteeNoteEvent::RETURNED_TO_CUSTOMER,
                    GuaranteeNoteEvent::TRANSFERRED_TO_OWNER,
                    GuaranteeNoteEvent::RETAINED_BY_GAMEPEK,
                ])->exists();
    }

    /** The note is no longer in GamePek's hands: returned, or given to the owner. */
    private function noteHasLeftGamePek(int $applicationId): bool
    {
        return GuaranteeNoteEvent::where('rental_application_id', $applicationId)
            ->whereIn('event', [
                GuaranteeNoteEvent::RETURNED_TO_CUSTOMER,
                GuaranteeNoteEvent::TRANSFERRED_TO_OWNER,
            ])->exists();
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }
}
