<?php

namespace App\Services\Notification\Contracts;

use App\Services\Notification\Dto\SmsSendResult;

/**
 * SMS-02 / SMS-03: the general "send a message" seam CLAUDE.md notes is
 * missing.
 *
 * App\Services\Otp\OtpProviderInterface is left exactly as it is. It has a
 * different contract -- the provider generates the code and we never see the
 * body -- and OtpService depends on it. Folding the two together would rewrite
 * working authentication for no gain.
 */
interface SmsSenderInterface
{
    public function key(): string;

    public function send(string $mobile, string $body, ?string $templateKey = null): SmsSendResult;

    /** SMS-03. Returns one of: queued|sent|delivered|failed|unknown. */
    public function deliveryStatus(string $providerMessageId): string;
}
