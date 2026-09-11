<?php

namespace App\Services\Rental;

use App\Enums\CustodyTransferType;
use App\Enums\DeviceOwnership;
use App\Enums\RentalInspectionStage;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\DeviceCustodyTransfer;
use App\Models\RentalApplication;
use App\Models\RentalDamageAssessment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use Illuminate\Support\Carbon;

/**
 * What stands between a Returned rental and closure -- REPORTED, never acted on.
 *
 * READ-ONLY. It writes nothing and closes nothing. The closure trigger is
 * undecided (config('rental.lifecycle.closure_trigger') is null), so `ready`
 * cannot be true today: the policy items below always report
 * `policy_undefined`. Each item says only what is factually present.
 *
 * Statuses:
 *   satisfied        the fact is recorded
 *   missing          the fact is not recorded yet
 *   not_applicable   the step does not apply (e.g. owner return for GamePek stock)
 *   policy_undefined whether/how this counts is an undecided business rule
 */
class RentalClosureReadiness
{
    public const SATISFIED = 'satisfied';

    public const MISSING = 'missing';

    public const NOT_APPLICABLE = 'not_applicable';

    public const POLICY_UNDEFINED = 'policy_undefined';

    /**
     * @return array{ready: bool, items: list<array{key: string, label: string, status: string, detail: string}>}
     */
    public function check(RentalApplication $application, ?Carbon $at = null): array
    {
        $at ??= now();
        $reservation = RentalReservation::where('rental_application_id', $application->id)->first();
        $device = $reservation?->device()->first();
        $isOwnerDevice = $device !== null && $device->ownership === DeviceOwnership::Owner;

        $completed = fn (RentalOperationType $type) => $reservation !== null && RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', $type->value)
            ->where('state', RentalOperationState::Completed->value)
            ->exists();

        $returnTransfer = $reservation === null ? null : DeviceCustodyTransfer::where('rental_reservation_id', $reservation->id)
            ->where('transfer_type', CustodyTransferType::CustomerToGamePek->value)
            ->possessionMoved()
            ->first();
        $deadline = $returnTransfer?->ownerDefectReportDeadline();

        $items = [];

        $items[] = $this->item('customer_return', 'دریافت دستگاه از مشتری',
            $completed(RentalOperationType::CustomerReturn) ? self::SATISFIED : self::MISSING, '');

        $items[] = $this->item('return_inspection', 'بازرسی دستگاه بازگشته',
            RentalInspection::where('rental_application_id', $application->id)
                ->where('stage', RentalInspectionStage::CustomerReturn->value)->exists()
                ? self::SATISFIED : self::MISSING, '');

        $items[] = $this->item('owner_defect_window', 'پایان مهلت دو ساعته اعلام ایراد توسط مالک',
            match (true) {
                ! $isOwnerDevice => self::NOT_APPLICABLE,
                $deadline === null => self::MISSING,
                $at->greaterThanOrEqualTo($deadline) => self::SATISFIED,
                default => self::MISSING,
            },
            $deadline ? 'پایان مهلت: '.$deadline->format('Y-m-d H:i') : '');

        $items[] = $this->item('owner_return', 'بازگرداندن دستگاه به مالک',
            ! $isOwnerDevice ? self::NOT_APPLICABLE
                : ($completed(RentalOperationType::OwnerReturn) ? self::SATISFIED : self::MISSING), '');

        // Whether an assessment is required when nothing is damaged is not
        // decided, so absence is reported as a policy question, not a gap.
        $items[] = $this->item('damage_assessment', 'ارزیابی خسارت توسط کارشناس',
            RentalDamageAssessment::where('rental_application_id', $application->id)->exists()
                ? self::SATISFIED : self::POLICY_UNDEFINED,
            'الزامی بودن ارزیابی در نبود خسارت تعیین نشده است.');

        $items[] = $this->item('settlement', 'محاسبه سهم گیم‌پک و مالک (۳۵/۶۵)',
            match (true) {
                ! $isOwnerDevice => self::NOT_APPLICABLE,
                $reservation !== null && RentalSettlement::where('rental_reservation_id', $reservation->id)->exists() => self::SATISFIED,
                config('rental.settlement.gross_basis') === null => self::POLICY_UNDEFINED,
                default => self::MISSING,
            }, 'محاسبه است، نه پرداخت.');

        $items[] = $this->item('deposit', 'تعیین تکلیف ودیعه', self::POLICY_UNDEFINED, 'قاعده ودیعه (B4) تعیین نشده است.');
        $items[] = $this->item('evidence_retention', 'نگهداری مدارک و تصاویر', self::POLICY_UNDEFINED, 'قاعده نگهداری (B11) تعیین نشده است.');
        $items[] = $this->item('closure_trigger', 'رویداد بستن اجاره',
            config('rental.lifecycle.closure_trigger') === null ? self::POLICY_UNDEFINED : self::SATISFIED,
            'رویداد بستن اجاره (B14) تعیین نشده است.');

        $ready = collect($items)->every(fn (array $i) => in_array($i['status'], [self::SATISFIED, self::NOT_APPLICABLE], true));

        return ['ready' => $ready, 'items' => $items];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::SATISFIED => 'انجام شده',
            self::MISSING => 'انجام نشده',
            self::NOT_APPLICABLE => 'موضوعیت ندارد',
            self::POLICY_UNDEFINED => 'قاعده تعیین نشده',
            default => $status,
        };
    }

    private function item(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
