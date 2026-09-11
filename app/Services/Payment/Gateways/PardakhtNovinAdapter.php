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
    private const NOT_CONFIGURED_MESSAGE = 'درگاه پرداخت پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.';

    public function __construct(private PardakhtNovinGateway $gateway) {}

    /**
     * The one credential the documented protocol requires in every request.
     * Read from config, never logged or echoed; only its presence is checked.
     */
    private function isConfigured(): bool
    {
        return trim((string) config('rental.payment.pardakhtnovin.corporation_pin')) !== '';
    }

    public function key(): string
    {
        return 'pardakhtnovin';
    }

    public function request(PaymentTransaction $transaction, string $callbackUrl): GatewayRequestResult
    {
        // FAIL CLOSED on missing credentials. Without this the wire class
        // would POST to the live bank endpoint with `CorporationPin: null` --
        // a real network call on a configuration that cannot possibly succeed.
        if (! $this->isConfigured()) {
            return new GatewayRequestResult(success: false, message: self::NOT_CONFIGURED_MESSAGE);
        }

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
        // Credentials removed after the customer paid: the payment may be real,
        // so this is not a refusal of it. Unknown keeps the row pending and
        // reconciliation can settle it once the configuration is restored.
        if (! $this->isConfigured()) {
            return new GatewayVerification(
                state: PaymentVerificationState::Unknown,
                statusCode: 'GATEWAY_NOT_CONFIGURED',
                message: self::NOT_CONFIGURED_MESSAGE,
            );
        }

        $result = $this->gateway->confirm((string) $transaction->authority);

        // A timeout, a dropped connection or a response with no Status is
        // "the gateway could not tell us" -- NOT "the customer did not pay".
        // The bank may already have taken the money, so marking the row failed
        // here would strand a real payment (a failed row can never be settled
        // again). Unknown leaves it pending for reconciliation to retry: the
        // exact meaning PaymentService gives that state.
        if (($result['status'] ?? null) === null) {
            return new GatewayVerification(
                state: PaymentVerificationState::Unknown,
                statusCode: 'NO_RESPONSE',
                message: 'پاسخی از درگاه پرداخت دریافت نشد. وضعیت پرداخت بعداً بررسی می‌شود.',
                raw: $result['raw'] ?? [],
            );
        }

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
        if (! $this->isConfigured()) {
            return new GatewayRefund(success: false, message: self::NOT_CONFIGURED_MESSAGE);
        }

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
