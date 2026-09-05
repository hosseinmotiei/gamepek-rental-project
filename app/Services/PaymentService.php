<?php

namespace App\Services;

use App\Enums\PaymentVerificationState;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\GatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PAY-01..PAY-05 orchestration.
 *
 * The four-armed match() that used to dispatch to private per-gateway methods
 * is gone; the gateways are now adapters behind PaymentGatewayInterface and
 * this class owns exactly one copy of the lifecycle: create the transaction,
 * lock it, verify server-side, mark the order paid, audit. Adding a gateway
 * no longer means adding two private methods here.
 *
 * The three public methods keep their exact signatures and return-array shapes
 * because CheckoutController reads $result['success'|'message'|'order'|
 * 'tracking_code'] and Admin\PaymentController relies on the same flow.
 *
 * The behavioural change that matters: an order is marked paid ONLY on the
 * strength of $gateway->verify(), a server-to-server call. Callback query
 * parameters are used to find the transaction and for nothing else. The mock
 * gateway previously read `Status=OK` straight out of the callback URL, which
 * let any visitor mark any pending order paid by editing that URL.
 */
class PaymentService
{
    public function __construct(
        private OrderService $orderService,
        private GatewayRegistry $gateways,
    ) {}

    /**
     * PAY-01. Signature unchanged.
     */
    public function initiatePayment(Order $order): array
    {
        $gateway = $this->gateways->for(config('rental.payment.gateway', 'mock'));

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'gateway' => $gateway->key(),
            'amount' => $order->total,
            'status' => 'pending',
            'verification_state' => PaymentVerificationState::Unverified->value,
            'correlation_id' => AuditLogger::correlationId(),
        ]);

        $callbackUrl = $this->callbackUrlFor($gateway->key());

        $result = $gateway->request($transaction, $callbackUrl);

        $transaction->update([
            'raw_request' => [
                'order_id' => $transaction->id,
                'amount' => $transaction->amount,
                'callback_url' => $callbackUrl,
            ],
            'raw_response' => $result->raw,
        ]);

        if (! $result->success) {
            $transaction->update(['status' => 'failed']);

            AuditLogger::log(
                action: 'payment.request',
                resourceType: 'PaymentTransaction',
                resourceId: $transaction->id,
                result: AuditLogger::RESULT_FAILURE,
                context: ['gateway' => $gateway->key(), 'order_id' => $order->id],
            );

            return ['success' => false, 'message' => $result->message ?? 'درگاه پرداخت در دسترس نیست.'];
        }

        $transaction->update(['authority' => $result->authority]);

        AuditLogger::log(
            action: 'payment.request',
            resourceType: 'PaymentTransaction',
            resourceId: $transaction->id,
            context: ['gateway' => $gateway->key(), 'order_id' => $order->id, 'amount' => $transaction->amount],
        );

        return [
            'success' => true,
            'gateway' => $gateway->key(),
            'authority' => $result->authority,
            'redirect_url' => $result->redirectUrl,
        ];
    }

    /**
     * PAY-02 + PAY-03. Signature unchanged.
     *
     * Idempotency is the same lock-then-check shape as OrderService::markAsPaid()
     * and the previous handlePardakhtNovinCallback(): the authoritative row is
     * re-fetched under lockForUpdate inside the transaction, and only a still-
     * `pending` row is allowed to proceed to verification.
     */
    public function handleCallback(array $params, string $gateway = 'mock'): array
    {
        $adapter = $this->gateways->for($gateway);
        $callback = $adapter->parseCallback($params);

        Log::info('Payment callback received', ['gateway' => $adapter->key(), 'params' => $params]);

        if (empty($callback->authority)) {
            Log::warning('Payment callback malformed: no authority', ['gateway' => $adapter->key()]);

            return ['success' => false, 'message' => 'اطلاعات بازگشتی از درگاه پرداخت نامعتبر است.'];
        }

        $transaction = PaymentTransaction::where('gateway', $adapter->key())
            ->where('authority', $callback->authority)
            ->first();

        if (! $transaction) {
            Log::warning('Payment callback: unknown authority', ['gateway' => $adapter->key()]);

            return ['success' => false, 'message' => 'تراکنش مورد نظر یافت نشد.'];
        }

        return $this->settle($transaction, $adapter, $params);
    }

    /**
     * PAY-03 exposed on its own, for the admin "verify" action and for
     * reconciliation. Safe to call repeatedly.
     */
    public function verifyTransaction(PaymentTransaction $transaction): array
    {
        return $this->settle($transaction, $this->gateways->for($transaction->gateway), []);
    }

    /**
     * PAY-04. Read-only inquiry -- never mutates the transaction, never marks
     * an order paid. May legitimately answer Unknown.
     */
    public function statusOf(PaymentTransaction $transaction): array
    {
        $verification = $this->gateways->for($transaction->gateway)->status($transaction);

        return [
            'success' => $verification->state !== PaymentVerificationState::Mismatch,
            'state' => $verification->state->value,
            'paid' => $verification->paid,
            'status_code' => $verification->statusCode,
            'message' => $verification->message,
        ];
    }

    /**
     * PAY-05.
     *
     * `reversed_at` (not `status`) is the authoritative "already refunded"
     * guard: a FAILED refund attempt leaves status at 'success' and
     * reversed_at null, so a legitimate retry after a transient failure stays
     * possible; only a row that already carries reversed_at is a duplicate.
     */
    public function refundPayment(PaymentTransaction $transaction, ?int $amountRial = null): array
    {
        $adapter = $this->gateways->for($transaction->gateway);

        try {
            return DB::transaction(function () use ($transaction, $adapter, $amountRial) {
                $locked = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

                if ($locked->reversed_at !== null) {
                    return ['success' => false, 'message' => 'این تراکنش قبلاً بازگشت داده شده است.'];
                }

                if ($locked->status !== 'success') {
                    return ['success' => false, 'message' => 'این تراکنش قابل بازگشت نیست.'];
                }

                $result = $adapter->refund($locked, $amountRial);

                $locked->update([
                    // The merchant secret is never persisted in these columns.
                    'reverse_raw_request' => ['authority' => $locked->authority, 'amount_rial' => $amountRial],
                    'reverse_raw_response' => $result->raw,
                ]);

                if (! $result->success) {
                    AuditLogger::log(
                        action: 'payment.refund',
                        resourceType: 'PaymentTransaction',
                        resourceId: $locked->id,
                        result: AuditLogger::RESULT_FAILURE,
                        context: ['gateway' => $adapter->key()],
                    );

                    return [
                        'success' => false,
                        'message' => $result->message ?? 'بازگشت وجه ناموفق بود. لطفاً بعداً دوباره تلاش کنید.',
                    ];
                }

                $locked->update(['status' => 'refunded', 'reversed_at' => now()]);

                // Order/inventory reconciliation runs inside this SAME
                // transaction: if it throws, the refund marking rolls back with
                // it and our own state is never left half-updated.
                $this->orderService->refundOrder($locked->order()->first());

                AuditLogger::log(
                    action: 'payment.refund',
                    resourceType: 'PaymentTransaction',
                    resourceId: $locked->id,
                    context: ['gateway' => $adapter->key(), 'reference' => $result->reference],
                );

                return ['success' => true, 'message' => 'بازگشت وجه با موفقیت انجام شد.'];
            });
        } catch (\Throwable $e) {
            Log::error('Payment refund reconciliation failed, transaction rolled back', [
                'transaction_id' => $transaction->id,
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'خطا در پردازش بازگشت وجه. لطفاً با پشتیبانی تماس بگیرید.'];
        }
    }

    /**
     * Kept for the existing admin call site. Signature unchanged.
     */
    public function reversePayment(string $token): array
    {
        $transaction = PaymentTransaction::where('authority', $token)->first();

        if (! $transaction) {
            return ['success' => false, 'message' => 'تراکنش مورد نظر یافت نشد.'];
        }

        return $this->refundPayment($transaction);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The one settlement path. Every caller -- public callback, admin verify,
    // reconciliation -- goes through here. Nothing else may mark an order paid.
    // ──────────────────────────────────────────────────────────────────────

    private function settle(PaymentTransaction $transaction, PaymentGatewayInterface $adapter, array $params): array
    {
        return DB::transaction(function () use ($transaction, $adapter, $params) {
            $locked = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($locked->status === 'success') {
                Log::info('Duplicate payment callback (already settled)', ['transaction_id' => $locked->id]);

                return [
                    'success' => true,
                    'tracking_code' => $locked->tracking_code,
                    'order' => $locked->order()->first()?->fresh(),
                ];
            }

            if ($locked->status !== 'pending') {
                Log::info('Stale payment callback', ['transaction_id' => $locked->id, 'status' => $locked->status]);

                return ['success' => false, 'message' => 'این تراکنش قبلاً پردازش شده است.'];
            }

            $verification = $adapter->verify($locked);

            $locked->update([
                'raw_response' => $params ? array_merge($params, $verification->raw) : $verification->raw,
                'gateway_status_code' => $verification->statusCode,
                'verification_state' => $verification->state->value,
                'verified_at' => now(),
            ]);

            // The gateway could not tell us. Leave the row pending so
            // reconciliation can try again -- never guess in either direction.
            if ($verification->state === PaymentVerificationState::Unknown) {
                $this->audit($locked, $adapter, AuditLogger::RESULT_FAILURE, 'unknown');

                return ['success' => false, 'message' => 'وضعیت پرداخت هنوز مشخص نیست. لطفاً چند دقیقه دیگر بررسی کنید.'];
            }

            if (! $verification->isVerifiedPaid()) {
                $locked->update(['status' => 'failed']);
                $this->orderService->markAsFailed($locked->order()->first());
                $this->audit($locked, $adapter, AuditLogger::RESULT_FAILURE, 'not_paid');

                return [
                    'success' => false,
                    'message' => $verification->message ?? 'پرداخت ناموفق بود.',
                ];
            }

            // Amount cross-check. Skipped only when the gateway's documented
            // response carries no amount at all (see PardakhtNovinAdapter),
            // never because the value was inconvenient.
            if ($verification->amountRial !== null && $verification->amountRial !== $locked->amount * 10) {
                $locked->update([
                    'status' => 'failed',
                    'verification_state' => PaymentVerificationState::Mismatch->value,
                ]);
                $this->orderService->markAsFailed($locked->order()->first());

                Log::error('Payment amount mismatch', [
                    'transaction_id' => $locked->id,
                    'expected_rial' => $locked->amount * 10,
                    'gateway_rial' => $verification->amountRial,
                ]);

                $this->audit($locked, $adapter, AuditLogger::RESULT_FAILURE, 'amount_mismatch');

                return ['success' => false, 'message' => 'مبلغ پرداخت با مبلغ سفارش مطابقت ندارد. لطفاً با پشتیبانی تماس بگیرید.'];
            }

            $locked->update([
                'status' => 'success',
                'tracking_code' => $verification->reference,
                'gateway_reference' => $verification->reference,
                'paid_at' => now(),
            ]);

            $this->orderService->markAsPaid($locked->order()->first(), $verification->reference);

            $this->audit($locked, $adapter, AuditLogger::RESULT_SUCCESS, 'paid');

            return [
                'success' => true,
                'tracking_code' => $verification->reference,
                'order' => $locked->order()->first()?->fresh(),
            ];
        });
    }

    private function audit(PaymentTransaction $transaction, PaymentGatewayInterface $adapter, string $result, string $outcome): void
    {
        AuditLogger::log(
            action: 'payment.verified',
            resourceType: 'PaymentTransaction',
            resourceId: $transaction->id,
            result: $result,
            context: [
                'gateway' => $adapter->key(),
                'outcome' => $outcome,
                'order_id' => $transaction->order_id,
                'status_code' => $transaction->gateway_status_code,
            ],
        );
    }

    private function callbackUrlFor(string $gatewayKey): string
    {
        return config('rental.payment.'.$gatewayKey.'.callback_url')
            ?: config('rental.payment.callback_url')
            ?: route('payment.callback');
    }
}
