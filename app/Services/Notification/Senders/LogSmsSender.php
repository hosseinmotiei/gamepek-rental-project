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
 * is configured and we must not pretend otherwise". Bound only in local and
 * testing (IntegrationServiceProvider refuses it anywhere else).
 *
 * Two deliberate limits:
 *  - the mobile number is masked in the log line, which may ship to shared
 *    sinks; the full body stays because reading it is the point of the driver;
 *  - it never reports a message as DELIVERED. Nothing was delivered, so a
 *    delivery check answers `unknown` and the row stays merely `sent`.
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
            'mobile' => self::mask($mobile),
            'template' => $templateKey,
            'body' => $body,
            'provider_message_id' => $id,
        ]);

        return new SmsSendResult(success: true, providerMessageId: $id, raw: ['driver' => 'log']);
    }

    public function deliveryStatus(string $providerMessageId): string
    {
        return 'unknown';
    }

    /** 0912***4567 -- enough to recognise, not enough to reuse. */
    public static function mask(string $mobile): string
    {
        $mobile = trim($mobile);

        return strlen($mobile) <= 7
            ? str_repeat('*', strlen($mobile))
            : substr($mobile, 0, 4).str_repeat('*', strlen($mobile) - 8).substr($mobile, -4);
    }
}
