<?php

namespace App\Enums;

/**
 * The registration lifecycle of a physical device.
 *
 * SCOPE: registration and admin review only. The post-approval operational
 * states -- picked up, received, inspected, delivered, on rent, returned,
 * settled -- are deliberately absent. They belong to the operations phase and
 * inventing them here would freeze decisions that have not been made.
 *
 * POLICY GATE: an owner may disable a device, and a penalty is understood to
 * apply. The penalty amount, formula and any suspension or reputation
 * consequence are UNDEFINED. Disabling is therefore recorded and nothing is
 * charged, deducted or escalated. Do not invent one.
 */
enum DeviceState: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::PendingReview => 'در انتظار بررسی',
            self::Approved => 'تأیید شده',
            self::Rejected => 'رد شده',
            self::Disabled => 'غیرفعال',
        };
    }

    /**
     * Is a device in this state part of the rentable fleet?
     *
     * Only approved devices are. Nothing in this phase consumes this yet --
     * reservations are still allocated at product level (see Phase 02) -- but
     * it is the predicate unit allocation will use.
     */
    public function isRentable(): bool
    {
        return $this === self::Approved;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview],
            self::PendingReview => [self::Approved, self::Rejected],
            self::Approved => [self::Disabled],
            self::Rejected => [self::PendingReview],
            self::Disabled => [self::Approved],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
