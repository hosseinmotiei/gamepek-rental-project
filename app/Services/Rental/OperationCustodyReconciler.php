<?php

namespace App\Services\Rental;

use App\Enums\CustodyActor;
use App\Enums\DeviceOwnership;
use App\Enums\RentalOperationState;
use App\Models\DeviceCustodyTransfer;
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

            // A GamePek-owned device closes as `not_required` and never as
            // `completed`, so reaching completion means a handover was claimed.
            $expectsHandover = $device->ownership === DeviceOwnership::Owner;

            if ($expectsHandover && ($transfer === null || ! $transfer->isPossessionMoved())) {
                $findings[] = $this->finding(
                    self::COMPLETED_WITHOUT_TRANSFER,
                    $operation,
                    $transfer,
                    'عملیات تکمیل شده است اما انتقال تحویل از مالک به گیم‌پک ثبت نشده است.'
                );
            }

            if ($expectsHandover && $device->currentCustody() !== CustodyActor::GamePek) {
                $findings[] = $this->finding(
                    self::COMPLETED_BUT_CUSTODY_NOT_GAMEPEK,
                    $operation,
                    $transfer,
                    'دستگاه پس از تکمیل عملیات باید در اختیار گیم‌پک باشد اما نیست.'
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
