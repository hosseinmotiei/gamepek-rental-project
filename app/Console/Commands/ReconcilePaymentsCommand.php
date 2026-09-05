<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentReconciler;
use Illuminate\Console\Command;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'PAY-06: ask the gateway about payment transactions still pending past the stale window.';

    public function handle(PaymentReconciler $reconciler): int
    {
        $report = $reconciler->run();

        $this->info(sprintf(
            'Examined %d, settled %d, failed %d, unknown %d.',
            $report['examined'],
            $report['settled'],
            $report['failed'],
            $report['unknown'],
        ));

        if ($report['unknown'] > 0) {
            $this->warn('Transactions needing manual review: '.implode(', ', $report['transactions']));
        }

        return self::SUCCESS;
    }
}
