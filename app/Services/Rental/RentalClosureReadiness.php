<?php

namespace App\Services\Rental;

use App\Enums\CustodyTransferType;
use App\Enums\DeviceOwnership;
use App\Enums\GuaranteeNoteStatus;
use App\Enums\RentalInspectionStage;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\DeviceCustodyTransfer;
use App\Models\RentalApplication;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\RentalSettlementCredit;
use Illuminate\Support\Carbon;

/**
 * What stands between a Returned rental and closure. READ-ONLY.
 *
 * The confirmed closure path: customer return -> return inspection -> damage
 * resolved (none / paid / note transferred to the owner) -> promissory note
 * returned or transferred -> owner's two-hour window over -> device back with
 * its owner -> settlement credited to the owner's wallet. Only when every
 * BLOCKING item is satisfied (or not applicable) may
 * RentalChainOrchestrator::close() run -- it re-checks this under a lock.
 *
 * Evidence retention (B11) is listed for visibility but is not a closure
 * prerequisite in the confirmed rules, so it does not block.
 */
class RentalClosureReadiness
{
    public const SATISFIED = 'satisfied';

    public const MISSING = 'missing';

    public const NOT_APPLICABLE = 'not_applicable';

    public const POLICY_UNDEFINED = 'policy_undefined';

    public function __construct(
        private RentalDamageAssessmentService $damage,
        private GuaranteeNoteService $notes,
    ) {}

    /**
     * @return array{ready: bool, items: list<array{key: string, label: string, status: string, detail: string, blocking: bool}>}
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

        $damage = $this->damage->statusFor($application->id)['status'];
        $note = $this->notes->statusFor($application->id);

        $settlement = $reservation === null ? null : RentalSettlement::where('rental_reservation_id', $reservation->id)->first();
        $credited = $settlement !== null && RentalSettlementCredit::where('rental_settlement_id', $settlement->id)->exists();

        $items = [];

        $items[] = $this->item('customer_return', 'دریافت دستگاه از مشتری',
            $completed(RentalOperationType::CustomerReturn) ? self::SATISFIED : self::MISSING);

        $items[] = $this->item('return_inspection', 'بازرسی دستگاه بازگشته',
            RentalInspection::where('rental_application_id', $application->id)
                ->where('stage', RentalInspectionStage::CustomerReturn->value)->exists()
                ? self::SATISFIED : self::MISSING);

        $items[] = $this->item('damage_resolution', 'تعیین تکلیف خسارت',
            match (true) {
                in_array($damage, [RentalDamageAssessmentService::NO_DAMAGE, RentalDamageAssessmentService::PAID], true) => self::SATISFIED,
                // Unpaid damage is resolved by the note's final outcome: to the
                // owner (owner device) or kept by GamePek (GamePek device).
                $damage === RentalDamageAssessmentService::UNPAID
                    && in_array($note, [GuaranteeNoteStatus::TransferredToOwner, GuaranteeNoteStatus::RetainedByGamePek], true) => self::SATISFIED,
                default => self::MISSING,
            },
            RentalDamageAssessmentService::statusLabel($damage));

        $items[] = $this->item('guarantee_note', 'تعیین تکلیف سفته',
            $note->isResolved() ? self::SATISFIED : self::MISSING, $note->label());

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
                : ($completed(RentalOperationType::OwnerReturn) ? self::SATISFIED : self::MISSING));

        $items[] = $this->item('settlement', 'واریز سهم مالک به کیف پول',
            match (true) {
                ! $isOwnerDevice => self::NOT_APPLICABLE,
                $credited => self::SATISFIED,
                default => self::MISSING,
            },
            $settlement === null ? 'محاسبه نشده' : ($credited ? 'واریز شده' : 'محاسبه‌شده — واریز نشده'));

        $items[] = $this->item('evidence_retention', 'نگهداری مدارک و تصاویر', self::POLICY_UNDEFINED,
            'قاعده نگهداری (B11) تعیین نشده است؛ پیش‌نیاز بستن نیست.', blocking: false);

        $ready = collect($items)
            ->filter(fn (array $i) => $i['blocking'])
            ->every(fn (array $i) => in_array($i['status'], [self::SATISFIED, self::NOT_APPLICABLE], true));

        return ['ready' => $ready, 'items' => $items];
    }

    /**
     * The four facts the confirmed settlement point waits for.
     */
    public function settlementPrerequisitesMet(RentalApplication $application): bool
    {
        $status = collect($this->check($application)['items'])->pluck('status', 'key');

        return collect(['customer_return', 'return_inspection', 'owner_defect_window', 'owner_return'])
            ->every(fn (string $key) => $status[$key] === self::SATISFIED);
    }

    /** @return list<string> the blocking items not yet satisfied */
    public static function missing(array $report): array
    {
        return collect($report['items'])
            ->filter(fn (array $i) => $i['blocking'] && ! in_array($i['status'], [self::SATISFIED, self::NOT_APPLICABLE], true))
            ->pluck('key')->values()->all();
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

    private function item(string $key, string $label, string $status, string $detail = '', bool $blocking = true): array
    {
        return compact('key', 'label', 'status', 'detail', 'blocking');
    }
}
