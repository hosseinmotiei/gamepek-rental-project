<?php

namespace App\Services\Banking\Dto;

readonly class OwnershipResult
{
    public function __construct(
        public bool $matched,
        public bool $conclusive = true,
        public ?string $ownerName = null,
        public ?string $bankName = null,
        public ?string $iban = null,
        public ?string $reference = null,
        public ?string $providerStatus = null,
        public array $raw = [],
    ) {}
}
