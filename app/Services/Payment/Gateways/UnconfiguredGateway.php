<?php

namespace App\Services\Payment\Gateways;

use App\Enums\PaymentVerificationState;
use App\Models\PaymentTransaction;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Dto\GatewayCallback;
use App\Services\Payment\Dto\GatewayRefund;
use App\Services\Payment\Dto\GatewayRequestResult;
use App\Services\Payment\Dto\GatewayVerification;

/**
 * The one object that answers for every gateway that is named but not wired:
 * Zarinpal and IDPay (which were four private stub methods on PaymentService),
 * and any misconfigured PAYMENT_GATEWAY value in production.
 *
 * It fails closed. verify() reports Mismatch, never Unknown -- Unknown means
 * "the gateway could not tell us", and reconciliation is allowed to retry
 * those. A gateway that does not exist has nothing to retry.
 */
class UnconfiguredGateway implements PaymentGatewayInterface
{
    public function __construct(private string $gatewayKey = 'unconfigured') {}

    public function key(): string
    {
        return $this->gatewayKey;
    }

    public function request(PaymentTransaction $transaction, string $callbackUrl): GatewayRequestResult
    {
        return new GatewayRequestResult(success: false, message: $this->message());
    }

    public function parseCallback(array $params): GatewayCallback
    {
        return new GatewayCallback(authority: null, params: $params);
    }

    public function verify(PaymentTransaction $transaction): GatewayVerification
    {
        return new GatewayVerification(
            state: PaymentVerificationState::Mismatch,
            statusCode: 'GATEWAY_NOT_CONFIGURED',
            message: $this->message(),
        );
    }

    public function status(PaymentTransaction $transaction): GatewayVerification
    {
        return $this->verify($transaction);
    }

    public function refund(PaymentTransaction $transaction, ?int $amountRial = null): GatewayRefund
    {
        return new GatewayRefund(success: false, message: $this->message());
    }

    private function message(): string
    {
        return 'درگاه پرداخت پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.';
    }
}
