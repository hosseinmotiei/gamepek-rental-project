<?php

namespace App\Enums;

/**
 * The lifecycle of one operational task.
 *
 * The ladder is deliberately short and every rung is something an operator can
 * actually observe:
 *
 *   pending                     just created, nothing evaluated yet
 *   awaiting_device_allocation  no physical device is attached to the
 *                               reservation, so no pickup can be planned
 *   scheduled                   a concrete device is attached
 *   in_progress                 GamePek has asked the owner for the device
 *   completed                   GamePek recorded physical receipt
 *   failed                      the pickup did not happen; reason recorded
 *   not_required                a GamePek-owned device was attached
 *
 * WHY `not_required` EXISTS
 *
 * The device is unknown when the task is created (allocation is an undecided
 * policy -- see the rental_reservations device_id migration), so the task
 * cannot know at birth whether an owner pickup is needed. When the attached
 * device turns out to be GamePek's own, custody is ALREADY GamePek and there is
 * nothing to pick up. Recording that as `not_required` is the honest outcome;
 * marking it `completed` would claim a handover that never happened, and
 * fabricating a GamePek -> GamePek transfer would be worse.
 *
 * This is not a cancellation concept and carries no policy: nothing is charged,
 * refunded, replaced or penalised by it.
 *
 * NOT modelled: cancellation, refund, replacement, owner penalty. Those are
 * undecided business policy (docs/business/CONFIRMED_DECISIONS.md section 4).
 */
enum RentalOperationState: string
{
    case Pending = 'pending';
    case AwaitingDeviceAllocation = 'awaiting_device_allocation';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'ثبت شده',
            self::AwaitingDeviceAllocation => 'در انتظار تخصیص دستگاه',
            self::Scheduled => 'زمان‌بندی شده',
            self::InProgress => 'در حال انجام',
            self::Completed => 'انجام شده',
            self::Failed => 'ناموفق',
            self::NotRequired => 'نیازی نیست',
        };
    }

    /**
     * Terminal states are terminal.
     *
     * A completed pickup must not be reopened. There is no correction workflow,
     * and inventing one would let a recorded handover be silently undone.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::NotRequired;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::AwaitingDeviceAllocation, self::Scheduled, self::NotRequired],
            self::AwaitingDeviceAllocation => [self::Scheduled, self::NotRequired],
            self::Scheduled => [self::InProgress, self::Failed],
            self::InProgress => [self::Completed, self::Failed],
            // A pickup that failed may be attempted again: the device and the
            // reservation are unchanged, so retrying is operationally
            // unambiguous. Nothing else about a failure is acted on.
            self::Failed => [self::InProgress],
            self::Completed, self::NotRequired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
