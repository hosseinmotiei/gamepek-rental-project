<?php

namespace App\Console\Commands;

use App\Enums\SmsState;
use App\Models\SmsMessage;
use App\Services\Notification\SmsService;
use Illuminate\Console\Command;

class SyncSmsDeliveryCommand extends Command
{
    protected $signature = 'sms:sync-delivery';

    protected $description = 'SMS-03/SMS-04: poll delivery status for sent messages and retry failed ones under the attempt cap.';

    public function handle(SmsService $service): int
    {
        $checked = 0;

        SmsMessage::where('state', SmsState::Sent->value)
            ->whereNotNull('provider_message_id')
            ->where(function ($q) {
                $q->whereNull('delivery_checked_at')
                    ->orWhere('delivery_checked_at', '<=', now()->subMinutes(30));
            })
            ->chunkById(100, function ($rows) use ($service, &$checked) {
                foreach ($rows as $message) {
                    $service->syncDeliveryStatus($message);
                    $checked++;
                }
            });

        $retried = $service->retryFailed();

        $this->info(sprintf('Checked delivery for %d message(s), retried %d.', $checked, $retried));

        return self::SUCCESS;
    }
}
