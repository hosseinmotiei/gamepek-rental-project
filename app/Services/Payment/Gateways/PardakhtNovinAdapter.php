<?php

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentVerificationState;
use App\Models\PaymentTransaction;
use App\Services\PardakhtNovinGateway;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Dto\GatewayCallback;
use App\Services\Payment\Dto\GatewayRefund;
use App\Services\Payment\Dto\GatewayRequestResult;
use App\Services\Payment\Dto\GatewayVerification;

/**
 * Thin adapter over the existing App\Services\PardakhtNovinGateway, which owns
 * the wire protocol and is NOT modified by this refactor. All of that class's
 * documented behaviour -- the field names taken from the official doc, the
 * Toman-to-Rial conversion contract, the response-code handling -- is
 * preserved exactly.
 *
 * `OrderId` sent to the gateway stays the PaymentTransaction's own id: the doc
 * requires a unique numeric per attempt, and `orders.order_number` is a
 * non-numeric string.
 */
class PardakhtNovinAdapter implements PaymentGatewayInterface
{
    public function __construct(private PardakhtNovinGateway $gateway) {}

    public function key(): string
    {
        return 'pardakhtnovin';
    }

    public function request(PaymentTransaction $transaction, string $callbackUrl): GatewayRequestResult
    {
        // Every amount in this codebase is Toman; Pardakht Novin is a
        // Shaparak-connected switch and expects Rial. Sending the raw Toman
        // value undercharges by exactly 10x.
        $amountInRial = $transaction->amount * 10;

        $result = $this->gateway->requestToken(
            orderId: $transaction->id,
            amount: $amountInRial,
            callbackUrl: $callbackUrl,
        );

        if (! $result['success']) {
            return new GatewayRequestResult(
                success: false,
                message: $result['message'] ?? 'درگاه پرداخت پرداخت نوین در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.',
                raw: $result['raw'],
            );
        }

        return new GatewayRequestResult(
            success: true,
            authority: $result['token'],
            redirectUrl: $this->gateway->redirectUrl($result['token']),
            raw: $result['raw'],
        );
    }

    public function parseCallback(array $params): GatewayCallback
    {
        // `Token` is the one field name used identically across every
        // documented operation (NormalSale response, Confirm and Reverse
        // request/response). The doc gives no field table for the callback
        // itself, so this extends a clearly consistent convention rather than
        // inventing a name.
        return new GatewayCallback(
            authority: array_find_ci($params, 'Token'),
            params: $params,
        );
    }

    public function verify(PaymentTransaction $transaction): GatewayVerification
    {
        $result = $this->gateway->confirm((string) $transaction->authority);

        if (! $result['success']) {
            return new GatewayVerification(
                state: PaymentVerificationState::Verified,
                paid: false,
                statusCode: $result['status'],
                message: 'پرداخت تایید نشد. در صورت کسر وجه، مبلغ ظرف چند دقیقه بازگردانده خواهد شد.',
                raw: $result['raw'],
            );
        }

        return new GatewayVerification(
            state: PaymentVerificationState::Verified,
            paid: true,
            reference: $result['rrn'],
            // Confirm's documented response carries no Amount field, so the
            // adapter cannot assert one. PaymentService skips its amount
            // cross-check when this is null rather than comparing against a
            // fabricated value.
            amountRial: null,
            statusCode: $result['status'],
            raw: $result['raw'],
        );
    }

    public function status(PaymentTransaction $transaction): GatewayVerification
    {
        // TODO(integration): the Pardakht Novin documentation (I.P.IT.012.00)
        // defines NormalSale, Confirm and Reverse only -- there is no
        // transaction-inquiry operation. Calling Confirm here would MUTATE the
        // transaction, which a read-only status check must never do, so this
        // reports Unknown. Reconciliation therefore flags such rows for a human
        // instead of resolving them automatically. Replace this the moment an
        // inquiry endpoint is documented.
        return new GatewayVerification(
            state: PaymentVerificationState::Unknown,
            statusCode: 'NO_INQUIRY_OPERATION',
            message: 'درگاه پرداخت نوین عملیات استعلام وضعیت ندارد.',
        );
    }

    public function refund(PaymentTransaction $transaction, ?int $amountRial = null): GatewayRefund
    {
        // Reverse is all-or-nothing in the doc; a partial amount cannot be
        // expressed, so $amountRial is deliberately ignored here.
        $result = $this->gateway->reverse((string) $transaction->authority);

        return new GatewayRefund(
            success: $result['success'],
            reference: $result['token'],
            message: $result['message'] ?? null,
            raw: $result['raw'],
        );
    }
}
