<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private OrderService $orderService,
        private PardakhtNovinGateway $pardakhtNovinGateway,
    ) {}

    /**
     * Initiate payment — returns redirect URL or payment data.
     */
    public function initiatePayment(Order $order): array
    {
        $gateway = config('rental.payment.gateway', 'mock');

        // Mock gateway is only permitted in local/testing environments. This
        // guards against ANY unrecognized/misconfigured gateway value falling
        // through to the match's default (mock) arm below in production --
        // not just the literal string 'mock' -- so a PAYMENT_GATEWAY typo can
        // never silently run mock in production.
        if (! in_array($gateway, ['zarinpal', 'idpay', 'pardakhtnovin'], true) && ! app()->environment(['local', 'testing'])) {
            return [
                'success' => false,
                'message' => 'درگاه پرداخت پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.',
            ];
        }

        return match ($gateway) {
            'zarinpal' => $this->initiateZarinpal($order),
            'idpay' => $this->initiateIdpay($order),
            'pardakhtnovin' => $this->initiatePardakhtNovin($order),
            default => $this->initiateMock($order),
        };
    }

    /**
     * Handle payment callback from gateway.
     */
    public function handleCallback(array $params, string $gateway = 'mock'): array
    {
        return match ($gateway) {
            'zarinpal' => $this->handleZarinpalCallback($params),
            'idpay' => $this->handleIdpayCallback($params),
            'pardakhtnovin' => $this->handlePardakhtNovinCallback($params),
            default => $this->handleMockCallback($params),
        };
    }

    // ──────────────────────────────────────────────────
    // Mock Gateway (Development)
    // ──────────────────────────────────────────────────

    private function initiateMock(Order $order): array
    {
        $authority = 'MOCK_'.Str::upper(Str::random(20));

        PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'status' => 'pending',
            'authority' => $authority,
            'raw_request' => ['order_id' => $order->id, 'amount' => $order->total],
        ]);

        $callbackUrl = config('rental.payment.callback_url')
            .'?Authority='.$authority
            .'&Status=OK'
            .'&gateway=mock';

        return [
            'success' => true,
            'gateway' => 'mock',
            'authority' => $authority,
            'redirect_url' => $callbackUrl,  // In dev: auto-redirect to success
        ];
    }

    private function handleMockCallback(array $params): array
    {
        $authority = $params['Authority'] ?? null;
        $status = $params['Status'] ?? 'NOK';

        $transaction = PaymentTransaction::where('authority', $authority)
            ->where('gateway', 'mock')
            ->with(['order.items.digitalCode', 'order.items.product', 'order.user.cart.items'])
            ->first();

        if (! $transaction || $status !== 'OK') {
            if ($transaction) {
                $transaction->update(['status' => 'failed', 'raw_response' => $params]);
                $this->orderService->markAsFailed($transaction->order);
            }

            return ['success' => false, 'message' => 'پرداخت ناموفق بود.'];
        }

        $trackingCode = 'MOCK_REF_'.strtoupper(Str::random(10));

        $transaction->update([
            'status' => 'success',
            'tracking_code' => $trackingCode,
            'paid_at' => now(),
            'raw_response' => $params,
        ]);

        $this->orderService->markAsPaid($transaction->order, $trackingCode);

        return [
            'success' => true,
            'tracking_code' => $trackingCode,
            'order' => $transaction->order->fresh(),
        ];
    }

    // ──────────────────────────────────────────────────
    // Pardakht Novin IPG (Sprint 3 Task 2 -- Phase 1: NormalSale only)
    // ──────────────────────────────────────────────────

    /**
     * NormalSale only. Confirm/Reverse and real callback verification are
     * explicitly out of scope for this phase -- see PardakhtNovinGateway's
     * class docblock. This method is pure orchestration: PaymentService owns
     * the PaymentTransaction lifecycle and OrderService integration point;
     * PardakhtNovinGateway owns nothing but the gateway's own wire protocol.
     *
     * OrderId sent to the gateway is this PaymentTransaction's own id, not
     * Order.id or Order.order_number -- the doc requires OrderId to be a
     * unique `long` per attempt (page 5), and order_number is a non-numeric
     * string. A fresh PaymentTransaction row already exists per attempt in
     * this codebase's existing design (mirrors initiateMock() exactly), so
     * its auto-increment id is already guaranteed unique per attempt without
     * any schema change.
     */
    private function initiatePardakhtNovin(Order $order): array
    {
        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'gateway' => 'pardakhtnovin',
            'amount' => $order->total,
            'status' => 'pending',
        ]);

        $callbackUrl = config('rental.payment.pardakhtnovin.callback_url')
            ?: route('payment.callback');

        // CRITICAL: every amount in this codebase ($order->total, prices,
        // everything shown to the customer) is in Toman, but Pardakht
        // Novin -- a Shaparak-connected switch (pna.shaparak.ir) -- expects
        // its `Amount` field in Rial (1 Toman = 10 Rial), like virtually
        // every Shaparak-certified gateway. Sending the raw Toman value
        // undercharges the customer by exactly 10x at the gateway while
        // the storefront still shows the correct (10x larger) Toman price.
        $amountInRial = $order->total * 10;

        $result = $this->pardakhtNovinGateway->requestToken(
            orderId: $transaction->id,
            amount: $amountInRial,
            callbackUrl: $callbackUrl,
        );

        $transaction->update([
            'raw_request' => ['OrderId' => $transaction->id, 'Amount' => $amountInRial, 'CallBackUrl' => $callbackUrl],
            'raw_response' => $result['raw'],
        ]);

        if (! $result['success']) {
            $transaction->update(['status' => 'failed']);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'درگاه پرداخت پرداخت نوین در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.',
            ];
        }

        $transaction->update(['authority' => $result['token']]);

        return [
            'success' => true,
            'gateway' => 'pardakhtnovin',
            'authority' => $result['token'],
            'redirect_url' => $this->pardakhtNovinGateway->redirectUrl($result['token']),
        ];
    }

    /**
     * Sprint 3 Task 3: Callback + Confirm.
     *
     * The documentation does not enumerate the exact parameters Pardakht
     * Novin sends to CallBackUrl (no field table exists for the callback
     * itself, unlike NormalSale/Confirm/Reverse which each have one) -- only
     * that "نتیجه تراکنش به آدرس پذیرنده در فیلد Callbackurl ... ارسال میگردد"
     * (the transaction result is sent to the merchant's CallBackUrl). `Token`
     * is read here because it is the one field name used identically across
     * every other documented operation (NormalSale response, Confirm
     * request/response, Reverse request/response) -- an extension of a
     * clearly-consistent convention, not an invented one.
     *
     * Idempotency (mirrors OrderService::markAsPaid()'s own lock-then-check
     * shape exactly): the PaymentTransaction row is locked and its status
     * re-checked before Confirm is ever called. Confirm is only attempted
     * from `pending` -- an already-`success` transaction returns its stored
     * result without calling Confirm or OrderService again; anything else
     * (`failed`, or any other non-pending state) is rejected as a stale/
     * duplicate callback. This is the primary guard; the gateway's own
     * -1533 "PaymentIsAlreadyConfirmed" response code is a secondary
     * backstop this code does not need to rely on under normal operation.
     */
    private function handlePardakhtNovinCallback(array $params): array
    {
        Log::info('PardakhtNovin callback received', ['params' => $params]);

        $token = array_find_ci($params, 'Token');

        if (empty($token)) {
            Log::warning('PardakhtNovin callback malformed: missing Token', ['params' => $params]);

            return [
                'success' => false,
                'message' => 'اطلاعات بازگشتی از درگاه پرداخت نامعتبر است.',
            ];
        }

        $transaction = PaymentTransaction::where('gateway', 'pardakhtnovin')
            ->where('authority', $token)
            ->with(['order.items.digitalCode', 'order.items.product', 'order.user.cart.items'])
            ->first();

        if (! $transaction) {
            Log::warning('PardakhtNovin callback: unknown token', ['token' => $token]);

            return [
                'success' => false,
                'message' => 'تراکنش مورد نظر یافت نشد.',
            ];
        }

        return DB::transaction(function () use ($transaction, $token) {
            // BUG-pattern parity with markAsPaid(): re-fetch and lock the
            // authoritative row inside this transaction rather than trusting
            // the possibly-stale $transaction already loaded above.
            $locked = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($locked->status === 'success') {
                Log::info('PardakhtNovin duplicate callback detected (already confirmed)', [
                    'transaction_id' => $locked->id,
                    'token' => $token,
                ]);

                return [
                    'success' => true,
                    'tracking_code' => $locked->tracking_code,
                    'order' => $locked->order()->first()?->fresh(),
                ];
            }

            if ($locked->status !== 'pending') {
                Log::info('PardakhtNovin duplicate/stale callback detected', [
                    'transaction_id' => $locked->id,
                    'token' => $token,
                    'status' => $locked->status,
                ]);

                return [
                    'success' => false,
                    'message' => 'این تراکنش قبلاً پردازش شده است.',
                ];
            }

            Log::info('PardakhtNovin confirm request', ['transaction_id' => $locked->id, 'token' => $token]);

            $confirmResult = $this->pardakhtNovinGateway->confirm($token);

            $locked->update(['raw_response' => $confirmResult['raw']]);

            if (! $confirmResult['success']) {
                $locked->update(['status' => 'failed']);

                Log::warning('PardakhtNovin confirm failed', [
                    'transaction_id' => $locked->id,
                    'status' => $confirmResult['status'],
                ]);

                $this->orderService->markAsFailed($locked->order);

                return [
                    'success' => false,
                    'message' => 'پرداخت تایید نشد. در صورت کسر وجه، مبلغ ظرف چند دقیقه بازگردانده خواهد شد.',
                ];
            }

            $trackingCode = $confirmResult['rrn'];

            $locked->update([
                'status' => 'success',
                'tracking_code' => $trackingCode,
                'paid_at' => now(),
            ]);

            $this->orderService->markAsPaid($locked->order, $trackingCode);

            Log::info('PardakhtNovin payment confirmed successfully', [
                'transaction_id' => $locked->id,
                'rrn' => $trackingCode,
            ]);

            return [
                'success' => true,
                'tracking_code' => $trackingCode,
                'order' => $locked->order()->first()?->fresh(),
            ];
        });
    }

    /**
     * Sprint 3 Task 4: Reverse (بازگشت خرید).
     *
     * public because this is intended to be called from an admin action
     * (not yet built -- out of scope for this task, which only covers the
     * orchestration method itself), unlike the private initiate-/handle-
     * prefixed gateway-dispatch methods above.
     *
     * Eligibility (per the doc's own narrative, page 3-4): Reverse only makes
     * sense after a successful Confirm ("تراکنش را تأیید" ... "میتواند ...
     * متد (بازگشت وجه) را فراخوانی نمایید") -- i.e. status must be 'success'.
     * The doc also states this should happen "کمتر از 15 دقیقه" (less than
     * 15 minutes) after confirmation; that window is enforced by the gateway
     * itself (response code -1552 "PaymentRequestIsNotEligibleToReversal"),
     * not duplicated here, since no explicit client-side deadline value is
     * documented.
     *
     * Idempotency: identical lock-then-check shape to handlePardakhtNovinCallback()/
     * markAsPaid(). `reversed_at` (not `status`) is the authoritative
     * "already reversed" guard -- a *failed* reverse attempt leaves `status`
     * at 'success' (the original payment was never undone) and `reversed_at`
     * null, so a legitimate retry after a transient failure remains possible;
     * only a row that already has `reversed_at` set is treated as a duplicate.
     */
    public function reversePayment(string $token): array
    {
        Log::info('PardakhtNovin reverse requested', ['token' => $token]);

        $transaction = PaymentTransaction::where('gateway', 'pardakhtnovin')
            ->where('authority', $token)
            ->with(['order.items.product', 'order.items.digitalCode'])
            ->first();

        if (! $transaction) {
            Log::warning('PardakhtNovin reverse: unknown transaction', ['token' => $token]);

            return [
                'success' => false,
                'message' => 'تراکنش مورد نظر یافت نشد.',
            ];
        }

        // Sprint 3 Task 4.5: the entire gateway-reverse-marking AND the
        // internal order/inventory/digital-code reconciliation below run
        // inside this ONE transaction. If reconciliation throws for any
        // reason, the whole transaction (including the PaymentTransaction's
        // own status='refunded'/reversed_at update) rolls back -- the
        // database is never left partially updated. The gateway-side reverse
        // itself cannot be undone at that point (see Known Limitations), but
        // our own state stays internally consistent either way.
        try {
            return DB::transaction(function () use ($transaction, $token) {
                $locked = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

                if ($locked->reversed_at !== null) {
                    Log::info('PardakhtNovin duplicate reverse detected', [
                        'transaction_id' => $locked->id,
                        'token' => $token,
                        'reversed_at' => $locked->reversed_at,
                    ]);

                    return [
                        'success' => false,
                        'message' => 'این تراکنش قبلاً بازگشت داده شده است.',
                    ];
                }

                if ($locked->status !== 'success') {
                    Log::warning('PardakhtNovin reverse rejected: transaction not eligible', [
                        'transaction_id' => $locked->id,
                        'token' => $token,
                        'status' => $locked->status,
                    ]);

                    return [
                        'success' => false,
                        'message' => 'این تراکنش قابل بازگشت نیست.',
                    ];
                }

                Log::info('PardakhtNovin reverse request', ['transaction_id' => $locked->id, 'token' => $token]);

                $reverseResult = $this->pardakhtNovinGateway->reverse($token);

                $locked->update([
                    // CorporationPin intentionally omitted, consistent with
                    // initiatePardakhtNovin()'s own raw_request (Sprint 3 Task 2)
                    // -- the merchant secret is never persisted in this column.
                    'reverse_raw_request' => ['Token' => $token],
                    'reverse_raw_response' => $reverseResult['raw'],
                ]);

                if (! $reverseResult['success']) {
                    Log::warning('PardakhtNovin reverse failed', [
                        'transaction_id' => $locked->id,
                        'status' => $reverseResult['status'],
                    ]);

                    return [
                        'success' => false,
                        'message' => $reverseResult['message'] ?? 'بازگشت وجه ناموفق بود. لطفاً بعداً دوباره تلاش کنید.',
                    ];
                }

                $locked->update([
                    'status' => 'refunded',
                    'reversed_at' => now(),
                ]);

                Log::info('PardakhtNovin reverse succeeded, reconciling order state', ['transaction_id' => $locked->id]);

                // Sprint 3 Task 4.5: reconcile Order/inventory/digital-code
                // state now that the gateway reverse has succeeded. Runs
                // inside this same transaction -- see the comment above.
                $this->orderService->refundOrder($transaction->order);

                Log::info('PardakhtNovin reverse and reconciliation both succeeded', ['transaction_id' => $locked->id]);

                return [
                    'success' => true,
                    'message' => 'بازگشت وجه با موفقیت انجام شد.',
                ];
            });
        } catch (\Throwable $e) {
            Log::error('PardakhtNovin reverse reconciliation failed, transaction rolled back', [
                'transaction_id' => $transaction->id,
                'token' => $token,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'خطا در پردازش بازگشت وجه. لطفاً با پشتیبانی تماس بگیرید.',
            ];
        }
    }

    // ──────────────────────────────────────────────────
    // Zarinpal (Production - stub)
    // ──────────────────────────────────────────────────

    private function initiateZarinpal(Order $order): array
    {
        // TODO: Integrate Zarinpal SDK before production
        // $merchant = config('rental.payment.zarinpal_merchant');
        // $callbackUrl = config('rental.payment.callback_url');
        return [
            'success' => false,
            'message' => 'درگاه پرداخت زرین‌پال هنوز پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.',
        ];
    }

    private function handleZarinpalCallback(array $params): array
    {
        return ['success' => false, 'message' => 'درگاه پرداخت زرین‌پال پیکربندی نشده است.'];
    }

    // ──────────────────────────────────────────────────
    // IDPay (Production - stub)
    // ──────────────────────────────────────────────────

    private function initiateIdpay(Order $order): array
    {
        // TODO: Integrate IDPay SDK before production
        return [
            'success' => false,
            'message' => 'درگاه پرداخت آیدی‌پی هنوز پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.',
        ];
    }

    private function handleIdpayCallback(array $params): array
    {
        return ['success' => false, 'message' => 'درگاه پرداخت آیدی‌پی پیکربندی نشده است.'];
    }
}
