<?php

namespace App\Services\Providers;

/**
 * Thrown by every `unconfigured` adapter.
 *
 * This is why the unconfigured adapters throw rather than returning a neutral
 * result: a verification step must never appear to have been performed because
 * nothing was wired up. Fail closed, loudly.
 */
class ProviderNotConfiguredException extends ProviderException
{
    public function __construct(string $service)
    {
        parent::__construct(
            message: sprintf('No provider is configured for the "%s" integration.', $service),
            persianMessage: 'این سرویس استعلام هنوز پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.',
            service: $service,
        );
    }
}
