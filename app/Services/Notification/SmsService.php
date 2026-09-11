<?php

namespace App\Services\Notification;

use App\Enums\SmsState;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\Contracts\SmsSenderInterface;
use App\Services\Providers\ProviderException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * SMS-02..SMS-06.
 *
 * Templates live in config('rental.sms.templates') and the transition-to-
 * template map in config('rental.sms.state_templates'). BOTH START EMPTY: no
 * customer-facing message copy has been approved, and inventing Persian
 * marketing/legal copy is not this code\'s call (TODO(business) B13). An
 * unknown template key sends nothing and is audited.
 */
class SmsService
{
    public function __construct(private SmsSenderInterface $sender) {}

    /**
     * Queue a templated message. Returns null when no template is configured
     * for the key -- silence is the correct behaviour, not an invented message.
     */
    /**
     * @param  string|null  $dedupeKey  the event this message is for (e.g.
     *                                  `transition:42`). When given, a second
     *                                  send for the same event returns the
     *                                  first message and sends nothing -- the
     *                                  unique index on sms_messages makes that
     *                                  hold even under a race.
     */
    public function send(string $templateKey, string $mobile, array $params = [], ?User $user = null, ?string $dedupeKey = null): ?SmsMessage
    {
        if ($dedupeKey !== null && ($existing = SmsMessage::where('dedupe_key', $dedupeKey)->first())) {
            return $existing;
        }

        $template = config('rental.sms.templates.'.$templateKey);

        if (! $template) {
            AuditLogger::log(
                action: 'sms.template_undefined',
                resourceType: 'SmsMessage',
                resourceId: null,
                result: AuditLogger::RESULT_DENIED,
                context: ['template_key' => $templateKey],
            );

            return null;
        }

        $body = $this->render($template, $params);

        try {
            $message = SmsMessage::create([
                'user_id' => $user?->id,
                'mobile' => $mobile,
                'template_key' => $templateKey,
                'dedupe_key' => $dedupeKey,
                'params' => $params,
                'body' => $body,
                'provider' => $this->sender->key(),
                'state' => SmsState::Queued,
                'queued_at' => now(),
                'correlation_id' => AuditLogger::correlationId(),
            ]);
        } catch (QueryException $e) {
            // The concurrent twin won the unique index: that message is the one.
            if ($dedupeKey !== null && ($e->errorInfo[1] ?? null) === 1062) {
                return SmsMessage::where('dedupe_key', $dedupeKey)->firstOrFail();
            }

            throw $e;
        }

        return $this->dispatch($message);
    }

    /** SMS-04: one attempt. Retry is driven by attempts/max_attempts. */
    public function dispatch(SmsMessage $message): SmsMessage
    {
        $maxAttempts = (int) config('rental.sms.max_attempts', 3);

        if ($message->attempts >= $maxAttempts) {
            $message->update(['state' => SmsState::Failed, 'failed_at' => now()]);

            return $message->refresh();
        }

        $message->update(['state' => SmsState::Sending, 'attempts' => $message->attempts + 1]);

        try {
            $result = $this->sender->send($message->mobile, $message->body, $message->template_key);
        } catch (ProviderException $e) {
            $message->update([
                'state' => SmsState::Failed,
                'last_error' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            Log::warning('SMS send failed', ['sms_message_id' => $message->id, 'error' => $e->getMessage()]);

            return $message->refresh();
        } catch (\Throwable $e) {
            // Anything else a sender throws -- a timeout, a TLS error, a bug in
            // an adapter -- would otherwise leave the row stuck in `sending`
            // forever, invisible to retryFailed(). Record the failure honestly.
            // Only the exception CLASS is stored: an unexpected exception's
            // message can carry a request URL or payload fragment.
            $message->update([
                'state' => SmsState::Failed,
                'last_error' => 'unexpected: '.$e::class,
                'failed_at' => now(),
            ]);

            report($e);

            return $message->refresh();
        }

        if (! $result->success) {
            $message->update([
                'state' => SmsState::Failed,
                'last_error' => $result->error,
                'failed_at' => now(),
            ]);

            return $message->refresh();
        }

        $message->update([
            'state' => SmsState::Sent,
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => now(),
            'last_error' => null,
        ]);

        AuditLogger::log(
            action: 'sms.sent',
            resourceType: 'SmsMessage',
            resourceId: $message->id,
            context: ['template_key' => $message->template_key, 'provider' => $this->sender->key()],
        );

        return $message->refresh();
    }

    /** SMS-03. */
    public function syncDeliveryStatus(SmsMessage $message): SmsMessage
    {
        if (! $message->provider_message_id) {
            return $message;
        }

        $status = $this->sender->deliveryStatus($message->provider_message_id);

        $message->update([
            'delivery_checked_at' => now(),
            'state' => match ($status) {
                'delivered' => SmsState::Delivered,
                'failed' => SmsState::Failed,
                default => $message->state,
            },
            'delivered_at' => $status === 'delivered' ? now() : $message->delivered_at,
        ]);

        return $message->refresh();
    }

    /** SMS-04: sweep messages that failed but are still under the attempt cap. */
    public function retryFailed(): int
    {
        $maxAttempts = (int) config('rental.sms.max_attempts', 3);
        $retried = 0;

        SmsMessage::where('state', SmsState::Failed->value)
            ->where('attempts', '<', $maxAttempts)
            ->chunkById(100, function ($rows) use (&$retried) {
                foreach ($rows as $message) {
                    $this->dispatch($message);
                    $retried++;
                }
            });

        return $retried;
    }

    private function render(string $template, array $params): string
    {
        $replacements = [];

        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $replacements['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
