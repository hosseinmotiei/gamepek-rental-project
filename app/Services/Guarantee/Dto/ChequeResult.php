<?php

namespace App\Services\Guarantee\Dto;

readonly class ChequeResult
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $outcome,
        public ?int $score = null,
        public array $fields = [],
        public ?string $reference = null,
        public ?string $providerStatus = null,
        public array $raw = [],
    ) {}

    public function passed(): bool
    {
        return $this->outcome === self::PASS;
    }
}
