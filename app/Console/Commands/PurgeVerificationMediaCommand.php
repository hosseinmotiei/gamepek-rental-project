<?php

namespace App\Console\Commands;

use App\Services\Media\VerificationMediaService;
use Illuminate\Console\Command;

class PurgeVerificationMediaCommand extends Command
{
    protected $signature = 'verification:purge-media';

    protected $description = 'VID-06: delete verification media whose retention window has passed, keeping the audit row.';

    public function handle(VerificationMediaService $service): int
    {
        $purged = $service->purgeExpired();

        $this->info(sprintf('Purged %d media file(s).', $purged));

        $undefined = collect(config('verification.media.retention_days', []))
            ->filter(fn ($days) => $days === null)
            ->keys();

        if ($undefined->isNotEmpty()) {
            // Never delete on a guessed policy -- these kinds are skipped
            // entirely until the owner sets a retention (TODO(business) B11).
            $this->warn('No retention policy set, so nothing was purged for: '.$undefined->implode(', '));
        }

        return self::SUCCESS;
    }
}
