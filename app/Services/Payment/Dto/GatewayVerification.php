<?php

namespace App\Services\Payment\Dto;

use App\Enums\PaymentVerificationState;

/**
 * PAY-03 / PAY-04 result.
 *
 * `state` distinguishes the three genuinely different answers: verified,
 * definitely-not, and cannot-tell. Unknown must never be collapsed into
 * either of the other two.
 */
readonly class GatewayVerification
{
    public function __construct(
        public PaymentVerificationState $state,
        public bool $paid = false,
        public ?string $reference = null,
        public ?int $amountRial = null,
        public ?string $statusCode = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public function isVerifiedPaid(): bool
    {
        return $this->state === PaymentVerificationState::Verified && $this->paid;
    }
}
