<?php

namespace App\Services\Rental;

use App\Enums\DeviceOwnership;
use App\Enums\GuaranteeNoteStatus;
use App\Enums\GuaranteeState;
use App\Enums\RentalApplicationState;
use App\Enums\RentalInspectionStage;
use App\Models\Guarantee;
use App\Models\GuaranteeNoteEvent;
use App\Models\RentalApplication;
use App\Models\RentalInspection;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `guarantee_note_events`: the physical promissory note.
 *
 * CONFIRMED RULES
 *  - GamePek holds the customer's promissory note; it is never cash, deposit
 *    or wallet money, and never enters a settlement.
 *  - No damage -> the note goes back to the customer.
 *  - Damage paid by the customer -> the note goes back to the customer.
 *  - Damage not paid -> the note is handed to the loss-bearing owner, who
 *    pursues it through the competent authorities. GamePek does not collect.
 *
 * Every reference -- guarantee, rental, owner, device, assessment -- is derived
 * here from the application, never from a request. Return and transfer are
 * mutually exclusive, enforced under a row lock and by unique indexes.
 *
 * CONFIRMED since: a GamePek-owned device has no owner, so for unpaid damage
 * the note stays with GamePek (retainByGamePek). A rental cancelled after
 * the note was received leaves it HELD: cancellation returns, transfers and
 * retains nothing, and no future action for that case is invented here.
 *
 * NOT DONE HERE: any legal wording, deadline for the customer to pay, court or
 * collection workflow. "The customer did not pay" is a staff decision recorded
 * by calling transferToOwner(); nothing infers it from elapsed time.
 */
class GuaranteeNoteService
{
    public function __construct(private RentalDamageAssessmentService $damage) {}

    public function statusFor(int $applicationId): GuaranteeNoteStatus
    {
        $events = GuaranteeNoteEvent::where('rental_application_id', $applicationId)->pluck('event')->all();

        return match (true) {
            in_array(GuaranteeNoteEvent::RETURNED_TO_CUSTOMER, $events, true) => GuaranteeNoteStatus::ReturnedToCustomer,
            in_array(GuaranteeNoteEvent::TRANSFERRED_TO_OWNER, $events, true) => GuaranteeNoteStatus::TransferredToOwner,
            in_array(GuaranteeNoteEvent::RETAINED_BY_GAMEPEK, $events, true) => GuaranteeNoteStatus::RetainedByGamePek,
            in_array(GuaranteeNoteEvent::RECEIVED, $events, true) => GuaranteeNoteStatus::HeldByGamePek,
            default => GuaranteeNoteStatus::NotReceived,
        };
    }

    /**
     * GamePek records physically receiving the customer's note.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function receive(RentalApplication $application, User $actor, ?string $notes = null): GuaranteeNoteEvent
    {
        return $this->write($application, $actor, GuaranteeNoteEvent::RECEIVED, $notes, function (RentalApplication $app) {
            if (! in_array($app->state, [
                RentalApplicationState::Approved,
                RentalApplicationState::Active,
                RentalApplicationState::Returned,
            ], true)) {
                throw new \RuntimeException('دریافت سفته فقط برای اجاره تأییدشده ثبت می‌شود.');
            }

            return [];
        });
    }

    /**
     * The note goes back to the customer: no damage, or damage paid.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function returnToCustomer(RentalApplication $application, User $actor, ?string $notes = null): GuaranteeNoteEvent
    {
        return $this->write($application, $actor, GuaranteeNoteEvent::RETURNED_TO_CUSTOMER, $notes, function (RentalApplication $app) {
            $this->assertReturnedAndInspected($app);

            $damage = $this->damage->statusFor($app->id);

            return match ($damage['status']) {
                RentalDamageAssessmentService::NO_DAMAGE => [
                    'basis' => GuaranteeNoteEvent::BASIS_NO_DAMAGE,
                    'rental_damage_assessment_id' => $damage['assessment']->id,
                ],
                RentalDamageAssessmentService::PAID => [
                    'basis' => GuaranteeNoteEvent::BASIS_DAMAGE_PAID,
                    'rental_damage_assessment_id' => $damage['assessment']->id,
                ],
                RentalDamageAssessmentService::UNPAID => throw new \RuntimeException('خسارت تعیین‌شده پرداخت نشده است و سفته قابل بازگرداندن نیست.'),
                default => throw new \RuntimeException('تا زمانی که نتیجه ارزیابی خسارت ثبت نشده باشد، سفته بازگردانده نمی‌شود.'),
            };
        });
    }

    /**
     * The customer did not pay an assessed damage: the note goes to the
     * loss-bearing owner for their own legal follow-up.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function transferToOwner(RentalApplication $application, User $actor, ?string $notes = null): GuaranteeNoteEvent
    {
        return $this->write($application, $actor, GuaranteeNoteEvent::TRANSFERRED_TO_OWNER, $notes, function (RentalApplication $app) {
            $this->assertReturnedAndInspected($app);

            $damage = $this->damage->statusFor($app->id);

            if ($damage['status'] !== RentalDamageAssessmentService::UNPAID) {
                throw new \RuntimeException('سفته فقط در صورت خسارت پرداخت‌نشده به مالک تحویل می‌شود.');
            }

            $device = RentalReservation::where('rental_application_id', $app->id)->firstOrFail()->device()->first();

            // The loss-bearing party must be a real third-party owner. For
            // GamePek's own stock the rule does not say what happens.
            if ($device === null || $device->ownership !== DeviceOwnership::Owner || $device->owner_id === null) {
                throw new \RuntimeException('این دستگاه مالک جداگانه‌ای ندارد و تحویل سفته به مالک برای آن تعریف نشده است.');
            }

            return [
                'owner_id' => $device->owner_id,
                'device_id' => $device->id,
                'rental_damage_assessment_id' => $damage['assessment']->id,
            ];
        });
    }

    /**
     * CONFIRMED: a GamePek-owned device has no owner to receive the note, so
     * for unpaid damage the note stays with GamePek. This records that
     * outcome -- it starts no legal or recovery workflow.
     *
     * @throws \RuntimeException with a Persian message
     */
    public function retainByGamePek(RentalApplication $application, User $actor, ?string $notes = null): GuaranteeNoteEvent
    {
        return $this->write($application, $actor, GuaranteeNoteEvent::RETAINED_BY_GAMEPEK, $notes, function (RentalApplication $app) {
            $this->assertReturnedAndInspected($app);

            $damage = $this->damage->statusFor($app->id);

            if ($damage['status'] !== RentalDamageAssessmentService::UNPAID) {
                throw new \RuntimeException('سفته فقط در صورت خسارت پرداخت‌نشده نزد گیم‌پک نگه داشته می‌شود.');
            }

            $device = RentalReservation::where('rental_application_id', $app->id)->firstOrFail()->device()->first();

            if ($device === null || $device->ownership !== DeviceOwnership::GamePek) {
                throw new \RuntimeException('این دستگاه مالک جداگانه دارد؛ سفته باید به مالک تحویل شود.');
            }

            return [
                'device_id' => $device->id,
                'rental_damage_assessment_id' => $damage['assessment']->id,
            ];
        });
    }

    private function assertReturnedAndInspected(RentalApplication $app): void
    {
        if ($app->state !== RentalApplicationState::Returned) {
            throw new \RuntimeException('سفته فقط پس از دریافت دستگاه از مشتری تعیین تکلیف می‌شود.');
        }

        $inspected = RentalInspection::where('rental_application_id', $app->id)
            ->where('stage', RentalInspectionStage::CustomerReturn->value)
            ->exists();

        if (! $inspected) {
            throw new \RuntimeException('تا زمانی که بازرسی دستگاه بازگشته ثبت نشده باشد، سفته تعیین تکلیف نمی‌شود.');
        }
    }

    /**
     * Shared write: lock the guarantee, answer an idempotent repeat, refuse a
     * contradiction, run the event's own checks, insert, audit.
     *
     * @param  callable(RentalApplication): array<string, mixed>  $checks
     */
    private function write(RentalApplication $application, User $actor, string $event, ?string $notes, callable $checks): GuaranteeNoteEvent
    {
        return DB::transaction(function () use ($application, $actor, $event, $notes, $checks) {
            $guarantee = Guarantee::where('rental_application_id', $application->id)->lockForUpdate()->first();

            if ($guarantee === null || $guarantee->state !== GuaranteeState::Verified) {
                throw new \RuntimeException('برای این اجاره ضمانت تأییدشده‌ای ثبت نشده است.');
            }

            $existing = GuaranteeNoteEvent::where('guarantee_id', $guarantee->id)->where('event', $event)->first();

            if ($existing) {
                return $existing;
            }

            $events = GuaranteeNoteEvent::where('guarantee_id', $guarantee->id)->pluck('event')->all();

            if ($event !== GuaranteeNoteEvent::RECEIVED) {
                if (! in_array(GuaranteeNoteEvent::RECEIVED, $events, true)) {
                    throw new \RuntimeException('دریافت سفته از مشتری هنوز ثبت نشده است.');
                }

                // Returned XOR transferred: whichever happened first stands.
                if (array_intersect($events, [
                    GuaranteeNoteEvent::RETURNED_TO_CUSTOMER,
                    GuaranteeNoteEvent::TRANSFERRED_TO_OWNER,
                    GuaranteeNoteEvent::RETAINED_BY_GAMEPEK,
                ]) !== []) {
                    throw new \RuntimeException('سفته این اجاره قبلاً تعیین تکلیف شده است.');
                }
            }

            $app = RentalApplication::whereKey($application->id)->firstOrFail();
            $fields = $checks($app);

            try {
                $row = new GuaranteeNoteEvent;
                $row->guarantee_id = $guarantee->id;
                $row->rental_application_id = $app->id;
                $row->event = $event;
                $row->final_marker = $event === GuaranteeNoteEvent::RECEIVED ? null : 1;
                $row->basis = $fields['basis'] ?? null;
                $row->rental_damage_assessment_id = $fields['rental_damage_assessment_id'] ?? null;
                $row->owner_id = $fields['owner_id'] ?? null;
                $row->device_id = $fields['device_id'] ?? null;
                $row->notes = $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 1000);
                $row->recorded_by_user_id = $actor->id;
                $row->occurred_at = now();
                $row->created_at = now();
                $row->save();
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new \RuntimeException('سفته این اجاره قبلاً تعیین تکلیف شده است.');
                }

                throw $e;
            }

            AuditLogger::log(
                action: 'guarantee_note.'.$event,
                resourceType: 'Guarantee',
                resourceId: $guarantee->id,
                context: [
                    'rental_application_id' => $app->id,
                    'basis' => $row->basis,
                    'owner_id' => $row->owner_id,
                    'device_id' => $row->device_id,
                    'rental_damage_assessment_id' => $row->rental_damage_assessment_id,
                ],
                actor: $actor,
            );

            return $row;
        });
    }
}
