<?php

namespace App\Services\Rental;

use App\Enums\RentalInspectionStage;
use App\Models\RentalDamageAssessment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `rental_damage_assessments`.
 *
 * CONFIRMED (C-39): GamePek's expert determines the damage amount. This
 * records that person's amount against the return inspection it came from.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: charge anyone, touch a wallet or a
 * deposit, compute or suggest an amount, categorise damage, or decide who is
 * responsible. The owner's two-hour window (C-38) is shown alongside, never
 * enforced here -- what follows it is undecided.
 */
class RentalDamageAssessmentService
{
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
            // Serialises with inspection and handover writes on the same task.
            $operation = RentalOperation::where('id', $inspection->rental_operation_id)->lockForUpdate()->firstOrFail();
            $inspection = RentalInspection::where('id', $inspection->id)->firstOrFail();

            // Damage is assessed on what came BACK from the customer.
            if ($inspection->stage !== RentalInspectionStage::CustomerReturn) {
                throw new \RuntimeException('ارزیابی خسارت فقط برای بازرسی دستگاه بازگشته از مشتری ثبت می‌شود.');
            }

            // The inspection must still describe its own task's rental and
            // console -- otherwise the amount would land on the wrong rental.
            if ($inspection->device_id !== $operation->device_id
                || $inspection->rental_reservation_id !== $operation->rental_reservation_id
                || $inspection->rental_application_id !== $operation->rental_application_id) {
                throw new \RuntimeException('ارجاعات این بازرسی با عملیات آن هم‌خوان نیست.');
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

    private function clean(?string $value, int $max): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }
}
