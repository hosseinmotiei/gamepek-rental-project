<?php

namespace App\Services\Payment;

use App\Enums\PaymentVerificationState;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogger;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PAY-06.
 *
 * A customer who closes the browser tab on the bank's page never reaches
 * /payment/callback, so the transaction sits at `pending` forever even though
 * money may have moved. This sweeps those rows and asks the gateway.
 *
 * Two rules it must never break:
 *
 *  1. It never writes `orders` itself. When the gateway says a stale pending
 *     transaction was in fact paid, it drives it through
 *     PaymentService::verifyTransaction(), i.e. the exact same settlement path
 *     the public callback uses -- there is one place that marks an order paid,
 *     and this is not a second one.
 *  2. An Unknown answer resolves nothing. Pardakht Novin documents no inquiry
 *     operation, so its adapter reports Unknown; those rows are audited and
 *     left for a human rather than being guessed either way.
 */
class PaymentReconciler
{
    public function __construct(
        private GatewayRegistry $gateways,
        private PaymentService $paymentService,
    ) {}

    /**
     * @return array{examined:int, settled:int, failed:int, unknown:int, transactions:list<int>}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();

        $staleAfter = (int) config('rental.payment.reconcile.stale_after_minutes', 30);
        $lookback = (int) config('rental.payment.reconcile.lookback_hours', 72);

        $stale = PaymentTransaction::where('status', 'pending')
            ->where('created_at', '<=', $now->copy()->subMinutes($staleAfter))
            ->where('created_at', '>=', $now->copy()->subHours($lookback))
            ->whereNotNull('authority')
            ->orderBy('id')
            ->get();

        $report = ['examined' => 0, 'settled' => 0, 'failed' => 0, 'unknown' => 0, 'transactions' => []];

        foreach ($stale as $transaction) {
            $report['examined']++;
            $report['transactions'][] = $transaction->id;

            // Each transaction gets its own correlation id so its audit rows
            // are distinguishable within one sweep.
            AuditLogger::useCorrelationId((string) Str::uuid());

            $status = $this->gateways->for($transaction->gateway)->status($transaction);

            if ($status->state === PaymentVerificationState::Unknown) {
                $report['unknown']++;

                $transaction->update(['reconciled_at' => $now]);

                AuditLogger::log(
                    action: 'payment.reconcile',
                    resourceType: 'PaymentTransaction',
                    resourceId: $transaction->id,
                    result: AuditLogger::RESULT_FAILURE,
                    context: [
                        'gateway' => $transaction->gateway,
                        'outcome' => 'unknown',
                        'note' => 'gateway offers no inquiry operation; needs manual review',
                    ],
                );

                continue;
            }

            if (! $status->paid) {
                $report['failed']++;

                // Route through the shared settlement path so the failure is
                // recorded exactly as a callback failure would be.
                $this->paymentService->verifyTransaction($transaction);
                $transaction->update(['reconciled_at' => $now]);

                AuditLogger::log(
                    action: 'payment.reconcile',
                    resourceType: 'PaymentTransaction',
                    resourceId: $transaction->id,
                    context: ['gateway' => $transaction->gateway, 'outcome' => 'not_paid'],
                );

                continue;
            }

            $result = $this->paymentService->verifyTransaction($transaction);
            $transaction->update(['reconciled_at' => $now]);

            if ($result['success'] ?? false) {
                $report['settled']++;
            } else {
                $report['failed']++;
            }

            AuditLogger::log(
                action: 'payment.reconcile',
                resourceType: 'PaymentTransaction',
                resourceId: $transaction->id,
                result: ($result['success'] ?? false) ? AuditLogger::RESULT_SUCCESS : AuditLogger::RESULT_FAILURE,
                context: ['gateway' => $transaction->gateway, 'outcome' => 'settled_from_reconciliation'],
            );
        }

        return $report;
    }
}
