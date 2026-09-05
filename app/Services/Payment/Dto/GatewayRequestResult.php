<?php

namespace App\Services\Payment\Dto;

/** PAY-01 result. Immutable, in the style of App\Support\Rental\RentalQuote. */
readonly class GatewayRequestResult
{
    public function __construct(
        public bool $success,
        public ?string $authority = null,
        public ?string $redirectUrl = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
