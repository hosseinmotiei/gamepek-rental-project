<?php

namespace App\Exceptions;

/**
 * The requested date range is no longer free for this product.
 *
 * Typed rather than a bare RuntimeException for one reason: the conflict has to
 * be audited, and the audit row must survive the rolled-back reservation
 * transaction. Callers catch this OUTSIDE the transaction, write the audit
 * there, and only then surface the Persian message. See
 * RentalReservationService.
 */
class ReservationConflictException extends \RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $rentalApplicationId = null,
        string $message = 'این دستگاه در بازه انتخابی شما رزرو شده است. لطفاً تاریخ دیگری انتخاب کنید.',
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function auditContext(): array
    {
        return [
            'product_id' => $this->productId,
            'start' => $this->startDate,
            'end' => $this->endDate,
            'rental_application_id' => $this->rentalApplicationId,
        ];
    }
}
