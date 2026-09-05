<?php

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentVerificationState;
use App\Models\PaymentTransaction;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Dto\GatewayCallback;
use App\Services\Payment\Dto\GatewayRefund;
use App\Services\Payment\Dto\GatewayRequestResult;
use App\Services\Payment\Dto\GatewayVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Development gateway. Local and testing only -- GatewayRegistry refuses to
 * hand it out anywhere else.
 *
 * SECURITY FIX (previously in PaymentService::handleMockCallback): the old
 * mock built its redirect as `.../payment/callback?Authority=…&Status=OK` and
 * then decided success from `$params['Status'] === 'OK'`. That is a query
 * string the customer's browser controls, so any visitor could mark any
 * pending order paid by editing a URL. It also trained the codebase on the
 * exact anti-pattern the rental requirements forbid.
 *
 * Now the redirect goes to an in-app confirmation page. That page records the
 * outcome SERVER-SIDE (below), and verify() reads only that server-side
 * record. Nothing in the callback query string can influence the decision.
 */
class MockGateway implements PaymentGatewayInterface
{
    private const OUTCOME_TTL_MINUTES = 30;

    public function key(): string
    {
        return 'mock';
    }

    public function request(PaymentTransaction $transaction, string $callbackUrl): GatewayRequestResult
    {
        $authority = 'MOCK_'.Str::upper(Str::random(20));

        return new GatewayRequestResult(
            success: true,
            authority: $authority,
            redirectUrl: route('payment.mock.confirm', ['authority' => $authority]),
            raw: ['order_id' => $transaction->order_id, 'amount' => $transaction->amount],
        );
    }

    public function parseCallback(array $params): GatewayCallback
    {
        return new GatewayCallback(
            authority: array_find_ci($params, 'Authority'),
            params: $params,
        );
    }

    public function verify(PaymentTransaction $transaction): GatewayVerification
    {
        $outcome = self::outcome($transaction->authority);

        if ($outcome === null) {
            // The customer reached /payment/callback without ever passing
            // through the confirmation page. Not a payment.
            return new GatewayVerification(
                state: PaymentVerificationState::Mismatch,
                statusCode: 'NO_OUTCOME',
                message: 'پرداختی برای این تراکنش ثبت نشده است.',
            );
        }

        if ($outcome !== 'paid') {
            return new GatewayVerification(
                state: PaymentVerificationState::Verified,
                paid: false,
                statusCode: 'CANCELLED',
                message: 'پرداخت توسط کاربر لغو شد.',
            );
        }

        return new GatewayVerification(
            state: PaymentVerificationState::Verified,
            paid: true,
            reference: 'MOCK_REF_'.strtoupper(substr(hash('sha256', (string) $transaction->authority), 0, 10)),
            amountRial: $transaction->amount * 10,
            statusCode: 'OK',
            raw: ['authority' => $transaction->authority, 'outcome' => $outcome],
        );
    }

    public function status(PaymentTransaction $transaction): GatewayVerification
    {
        return $this->verify($transaction);
    }

    public function refund(PaymentTransaction $transaction, ?int $amountRial = null): GatewayRefund
    {
        self::recordOutcome((string) $transaction->authority, 'refunded');

        return new GatewayRefund(
            success: true,
            reference: 'MOCK_REFUND_'.strtoupper(Str::random(8)),
            raw: ['amount_rial' => $amountRial ?? $transaction->amount * 10],
        );
    }

    /**
     * Called by the in-app confirmation page. This is the only writer of the
     * outcome, and it runs server-side on a POST from an authenticated owner.
     */
    public static function recordOutcome(string $authority, string $outcome): void
    {
        Cache::put(self::cacheKey($authority), $outcome, now()->addMinutes(self::OUTCOME_TTL_MINUTES));
    }

    public static function outcome(?string $authority): ?string
    {
        return $authority ? Cache::get(self::cacheKey($authority)) : null;
    }

    private static function cacheKey(string $authority): string
    {
        return 'payment:mock:'.$authority;
    }
}
