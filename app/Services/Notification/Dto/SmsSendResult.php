<?php

namespace App\Services\Notification\Dto;

readonly class SmsSendResult
{
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}
}
