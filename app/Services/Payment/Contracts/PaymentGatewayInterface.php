<?php

namespace App\Services\Payment\Contracts;

use App\Models\PaymentTransaction;
use App\Services\Payment\Dto\GatewayCallback;
use App\Services\Payment\Dto\GatewayRefund;
use App\Services\Payment\Dto\GatewayRequestResult;
use App\Services\Payment\Dto\GatewayVerification;

/**
 * PAY-01..PAY-05.
 *
 * This replaces the four-armed match() that PaymentService used to dispatch on
 * -- it is the same dispatch made typed, not a new abstraction layer. No new
 * payment gateway is introduced: the implementations are the existing mock,
 * the existing Pardakht Novin wire client, and one "not configured" object
 * that replaces the zarinpal/idpay stub methods.
 *
 * The contract that matters:
 *
 *   parseCallback() PARSES. It never decides. A callback is a URL the
 *   customer's browser can be made to fetch with any query string, so nothing
 *   in it may be treated as proof of payment.
 *
 *   verify() DECIDES, using a server-to-server call the customer cannot forge.
 *   PaymentService only ever marks an order paid on the strength of verify().
 */
interface PaymentGatewayInterface
{
    /** Stable key, matching config('rental.payment.gateways') and payment_transactions.gateway. */
    public function key(): string;

    /** PAY-01 — ask the gateway for a token / authority and a redirect target. */
    public function request(PaymentTransaction $transaction, string $callbackUrl): GatewayRequestResult;

    /** PAY-02 — pull the identifying fields out of an untrusted callback. Parse only. */
    public function parseCallback(array $params): GatewayCallback;

    /** PAY-03 — authoritative server-side verification. The only source of "paid". */
    public function verify(PaymentTransaction $transaction): GatewayVerification;

    /** PAY-04 — read-only status inquiry, used by reconciliation. May return Unknown. */
    public function status(PaymentTransaction $transaction): GatewayVerification;

    /** PAY-05 — refund / reverse. $amountRial null means the full amount. */
    public function refund(PaymentTransaction $transaction, ?int $amountRial = null): GatewayRefund;
}
