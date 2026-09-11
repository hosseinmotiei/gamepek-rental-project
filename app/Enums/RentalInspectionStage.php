<?php

namespace App\Enums;

/**
 * When an inspection was performed.
 *
 * Derived from the operation it is recorded against, never chosen by a form:
 * see RentalOperationType::inspectionStage(). Deliberately carries no outcome,
 * severity or grade -- no damage taxonomy is defined, and an invented scale
 * would read as a policy nobody decided.
 */
enum RentalInspectionStage: string
{
    /** The condition check at the customer's door during delivery. */
    case Delivery = 'delivery';

    /** The check when GamePek receives the device back from the customer. */
    case CustomerReturn = 'customer_return';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'هنگام تحویل به مشتری',
            self::CustomerReturn => 'هنگام دریافت از مشتری',
        };
    }
}
