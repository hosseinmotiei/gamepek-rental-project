<?php

namespace App\Services\Rental;

use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `rental_inspections`.
 *
 * Records condition evidence against a delivery or a customer return whose
 * handover has actually been recorded. Every reference on the row -- device,
 * reservation, application, custody transfer, stage -- is DERIVED from the
 * operation under a lock and cross-checked, never taken from a request. An
 * inspection therefore cannot be filed against the wrong rental or the wrong
 * console, however the form is tampered with.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * No damage amount, severity, category, repair price or depreciation. The
 * confirmed rule is that GamePek's expert determines the damage amount (C-39);
 * how, and what follows financially, is not decided. The findings are free
 * text and nothing reads them to trigger anything.
 */
class RentalInspectionService
{
    /**
     * @throws \RuntimeException with a Persian message
     */
    public function record(RentalOperation $operation, User $actor, string $findings): RentalInspection
    {
        $findings = trim($findings);

        if ($findings === '') {
            throw new \RuntimeException('ثبت شرح بازرسی الزامی است.');
        }

        return DB::transaction(function () use ($operation, $actor, $findings) {
            // Serialises with the handover writes on the same row.
            $locked = RentalOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            $stage = $locked->type->inspectionStage()
                ?? throw new \RuntimeException('برای این نوع عملیات بازرسی تعریف نشده است.');

            $device = $locked->device()->first()
                ?? throw new \RuntimeException('برای این عملیات دستگاهی مشخص نشده است.');

            $reservation = $locked->reservation()->first();

            if ($reservation === null
                || $reservation->device_id !== $device->id
                || $reservation->rental_application_id !== $locked->rental_application_id) {
                throw new \RuntimeException('دستگاه یا درخواست این عملیات با رزرو مربوطه هم‌خوان نیست.');
            }

            $transfer = $locked->custodyTransfer()->first();

            // Evidence about a handover that has not happened would be
            // evidence about nothing.
            if ($transfer === null || ! $transfer->isPossessionMoved()) {
                throw new \RuntimeException('تا زمانی که تحویل فیزیکی ثبت نشده باشد، بازرسی قابل ثبت نیست.');
            }

            if ($transfer->device_id !== $device->id
                || $transfer->rental_reservation_id !== $reservation->id
                || $transfer->transfer_type !== $locked->type->custodyTransferType()) {
                throw new \RuntimeException('سابقه تحویل این عملیات به دستگاه یا رزرو دیگری اشاره می‌کند.');
            }

            $inspection = new RentalInspection;
            $inspection->rental_operation_id = $locked->id;
            $inspection->device_custody_transfer_id = $transfer->id;
            $inspection->rental_reservation_id = $reservation->id;
            $inspection->rental_application_id = $locked->rental_application_id;
            $inspection->device_id = $device->id;
            $inspection->stage = $stage;
            $inspection->findings = mb_substr($findings, 0, 2000);
            $inspection->inspected_by_user_id = $actor->id;
            $inspection->inspected_at = now();
            $inspection->created_at = now();
            $inspection->save();

            AuditLogger::log(
                action: 'inspection.recorded',
                resourceType: 'RentalInspection',
                resourceId: $inspection->id,
                context: [
                    'rental_operation_id' => $locked->id,
                    'operation_number' => $locked->operation_number,
                    'custody_transfer_reference' => $transfer->reference_number,
                    'rental_application_id' => $locked->rental_application_id,
                    'device_id' => $device->id,
                    // Never the raw serial.
                    'device_serial_mask' => $device->maskedSerial(),
                    'stage' => $stage->value,
                ],
                actor: $actor,
            );

            return $inspection;
        });
    }
}
