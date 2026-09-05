<?php

namespace App\Services\Payment\Dto;

/**
 * PAY-02. The identifying fields extracted from an untrusted callback.
 *
 * There is deliberately no `success` field on this object: a callback cannot
 * establish success. Only GatewayVerification can.
 */
readonly class GatewayCallback
{
    public function __construct(
        public ?string $authority,
        public array $params = [],
    ) {}
}
