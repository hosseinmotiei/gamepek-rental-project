<?php

namespace App\Services\Notification\Senders;

use App\Services\Notification\Contracts\SmsSenderInterface;
use App\Services\Notification\Dto\SmsSendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development sender. Writes to the log, never to a network.
 *
 * Mirrors NullOtpProvider, which is the existing pattern for "no SMS provider
 * is configured and we must not pretend otherwise".
 */
class LogSmsSender implements SmsSenderInterface
{
    public function key(): string
    {
        return 'log';
    }

    public function send(string $mobile, string $body, ?string $templateKey = null): SmsSendResult
    {
        $id = 'LOG-'.strtoupper(Str::random(12));

        Log::info('SMS (log driver)', [
            'mobile' => $mobile,
            'template' => $templateKey,
            'body' => $body,
            'provider_message_id' => $id,
        ]);

        return new SmsSendResult(success: true, providerMessageId: $id, raw: ['driver' => 'log']);
    }

    public function deliveryStatus(string $providerMessageId): string
    {
        return 'delivered';
    }
}
