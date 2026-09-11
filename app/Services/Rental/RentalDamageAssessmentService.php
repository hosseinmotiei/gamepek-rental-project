<?php

namespace App\Services\Rental;

use App\Enums\RentalInspectionStage;
use App\Models\GuaranteeNoteEvent;
use App\Models\RentalApplication;
use App\Models\RentalDamageAssessment;
use App\Models\RentalDamagePayment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
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
 * NOT DONE HERE: no formula, no category, no gateway, no wallet movement --
 * where a damage payment's money goes is not decided.
 */
class RentalDamageAssessmentService
{
    public const NOT_ASSESSED = 'not_assessed';

    public const NO_DAMAGE = 'no_damage';

    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

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

            if ($current->amount === 0) {
                throw new \RuntimeException('برای این اجاره خسارتی تعیین نشده است که پرداخت شود.');
            }

            if (GuaranteeNoteEvent::where('rental_application_id', $application->id)->whereNotNull('final_marker')->exists()) {
                throw new \RuntimeException('سفته این اجاره تعیین تکلیف شده است و پرداخت خسارت دیگر از طریق گیم‌پک ثبت نمی‌شود.');
            }

            $payment = new RentalDamagePayment;
            $payment->rental_damage_assessment_id = $current->id;
            $payment->rental_application_id = $application->id;
            $payment->amount = $current->amount;
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

    private function isSettled(int $applicationId): bool
    {
        return RentalDamagePayment::where('rental_application_id', $applicationId)->exists()
            || GuaranteeNoteEvent::where('rental_application_id', $applicationId)->whereNotNull('final_marker')->exists();
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }
}
