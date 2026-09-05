<?php

namespace App\Services\Providers;

/**
 * Base for every external-integration failure.
 *
 * `$persianMessage` is what may be shown to a customer;
 * getMessage() stays English/technical for logs. This split exists because
 * .claude/rules/backend-services.md forbids forwarding a raw exception message
 * to the user -- it leaks internal and English text.
 */
class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $persianMessage = 'خطا در ارتباط با سرویس استعلام. لطفاً بعداً دوباره تلاش کنید.',
        public readonly ?string $providerCode = null,
        public readonly string $service = 'unknown',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
