<?php

namespace App\Services\Notification\Senders;

use App\Services\Notification\Contracts\SmsSenderInterface;
use App\Services\Notification\Dto\SmsSendResult;
use App\Services\Providers\ProviderNotConfiguredException;

/**
 * Bound in production until the owner confirms an SMS provider. CLAUDE.md is
 * explicit that live SMS behaviour must not be implemented before then.
 */
class UnconfiguredSmsSender implements SmsSenderInterface
{
    public function key(): string
    {
        return 'unconfigured';
    }

    public function send(string $mobile, string $body, ?string $templateKey = null): SmsSendResult
    {
        throw new ProviderNotConfiguredException('sms');
    }

    public function deliveryStatus(string $providerMessageId): string
    {
        return 'unknown';
    }
}
