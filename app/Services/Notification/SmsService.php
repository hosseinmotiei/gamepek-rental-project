<?php

namespace App\Services\Notification;

use App\Enums\SmsState;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\Contracts\SmsSenderInterface;
use App\Services\Providers\ProviderException;
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
    public function send(string $templateKey, string $mobile, array $params = [], ?User $user = null): ?SmsMessage
    {
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

        $message = SmsMessage::create([
            'user_id' => $user?->id,
            'mobile' => $mobile,
            'template_key' => $templateKey,
            'params' => $params,
            'body' => $body,
            'provider' => $this->sender->key(),
            'state' => SmsState::Queued,
            'queued_at' => now(),
            'correlation_id' => AuditLogger::correlationId(),
        ]);

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
