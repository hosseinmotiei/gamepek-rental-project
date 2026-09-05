<?php

namespace App\Services\Payment\Dto;

/** PAY-05 result. */
readonly class GatewayRefund
{
    public function __construct(
        public bool $success,
        public ?string $reference = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
