<?php

namespace App\Services\Identity\Dto;

/**
 * One identity check's answer.
 *
 * `matched` is a three-state question collapsed carefully: false with
 * `conclusive = true` means the registry said no; false with
 * `conclusive = false` means we could not find out, which must route to manual
 * review rather than to a rejection.
 */
readonly class IdentityCheckResult
{
    public function __construct(
        public bool $matched,
        public bool $conclusive = true,
        public ?int $score = null,
        public ?string $reference = null,
        public ?string $providerStatus = null,
        public array $fields = [],
        public array $raw = [],
    ) {}
}
