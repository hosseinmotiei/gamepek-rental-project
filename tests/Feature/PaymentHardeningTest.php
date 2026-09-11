<?php

namespace Tests\Feature;

use App\Enums\PaymentVerificationState;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Dto\GatewayCallback;
use App\Services\Payment\Dto\GatewayRefund;
use App\Services\Payment\Dto\GatewayRequestResult;
use App\Services\Payment\Dto\GatewayVerification;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\PardakhtNovinAdapter;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The payment branches the happy-path tests never reach.
 *
 * The amount cross-check, the "gateway could not tell us" state and the real
 * adapter's behaviour on missing credentials or a dropped connection all sit in
 * PaymentService::settle() / PardakhtNovinAdapter. Each is driven here through
 * the real code path -- a test gateway registered through GatewayRegistry's own
 * config, and Http::fake() in front of the real wire class -- never by calling a
 * private method or asserting against a mock of the thing under test.
 */
class PaymentHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedGateway::$verification = null;
        config()->set('rental.payment.gateways.scripted', ScriptedGateway::class);
    }

    // ── Amount and uncertainty ───────────────────────────────────────────

    public function test_a_verified_payment_for_the_wrong_amount_is_refused(): void
    {
        [$order, $transaction] = $this->pendingTransaction('scripted', total: 500_000);

        // The gateway says "paid" -- for a tenth of the price.
        ScriptedGateway::$verification = new GatewayVerification(
            state: PaymentVerificationState::Verified,
            paid: true,
            reference: 'REF-WRONG-AMOUNT',
            amountRial: 500_000,
            statusCode: 'OK',
        );

        $result = app(PaymentService::class)->verifyTransaction($transaction);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(PaymentVerificationState::Mismatch, $transaction->fresh()->verification_state);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, RentalReservation::count());
    }

    public function test_a_gateway_that_cannot_answer_leaves_the_payment_pending_and_retryable(): void
    {
        [$order, $transaction] = $this->pendingTransaction('scripted', total: 500_000);

        ScriptedGateway::$verification = new GatewayVerification(
            state: PaymentVerificationState::Unknown,
            statusCode: 'TIMEOUT',
        );

        $first = app(PaymentService::class)->verifyTransaction($transaction);

        // Neither paid nor failed: nothing was guessed in either direction.
        $this->assertFalse($first['success']);
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertNotSame('paid', $order->fresh()->payment_status);

        // The gateway answers later; the same row settles, once.
        ScriptedGateway::$verification = new GatewayVerification(
            state: PaymentVerificationState::Verified,
            paid: true,
            reference: 'REF-LATER',
            amountRial: 5_000_000,
            statusCode: 'OK',
        );

        $this->assertTrue(app(PaymentService::class)->verifyTransaction($transaction->fresh())['success']);
        $this->assertTrue(app(PaymentService::class)->verifyTransaction($transaction->fresh())['success']);

        $this->assertSame('success', $transaction->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::count());
    }

    // ── The real adapter, fail-closed ────────────────────────────────────

    public function test_the_real_gateway_refuses_before_any_network_call_when_unconfigured(): void
    {
        config()->set('rental.payment.gateway', 'pardakhtnovin');
        config()->set('rental.payment.pardakhtnovin.corporation_pin', null);
        Http::fake();

        $order = $this->order(500_000);

        $result = app(PaymentService::class)->initiatePayment($order);

        $this->assertFalse($result['success']);
        $this->assertSame('درگاه پرداخت پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.', $result['message']);
        Http::assertNothingSent();
        $this->assertSame('failed', PaymentTransaction::firstOrFail()->status);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_dropped_confirm_call_is_unknown_not_a_failed_payment(): void
    {
        config()->set('rental.payment.pardakhtnovin.corporation_pin', 'test-pin-not-real');

        // The bank may already have taken the money; the connection dropped
        // before it could say so.
        Http::fake(fn () => throw new ConnectionException('timed out'));

        [$order, $transaction] = $this->pendingTransaction('pardakhtnovin', total: 500_000);

        $result = app(PaymentService::class)->verifyTransaction($transaction);

        $this->assertFalse($result['success']);
        $this->assertSame('pending', $transaction->fresh()->status,
            'a transaction the gateway could not answer for must stay settleable');
        $this->assertNotSame('failed', $order->fresh()->payment_status);
        $this->assertSame(0, RentalReservation::count());
    }

    public function test_removing_credentials_after_payment_does_not_fail_a_real_payment(): void
    {
        config()->set('rental.payment.pardakhtnovin.corporation_pin', '');
        Http::fake();

        [, $transaction] = $this->pendingTransaction('pardakhtnovin', total: 500_000);

        app(PaymentService::class)->verifyTransaction($transaction);

        $this->assertSame('pending', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_real_adapter_never_sends_the_pin_anywhere_but_the_bank(): void
    {
        config()->set('rental.payment.pardakhtnovin.corporation_pin', 'SECRET-PIN-12345');
        Http::fake(['*' => Http::response(['Status' => '0', 'Token' => 'TOK-1'], 200)]);
        Log::spy();

        [, $transaction] = $this->pendingTransaction('pardakhtnovin', total: 500_000);
        app(PardakhtNovinAdapter::class)->request($transaction, 'https://example.test/callback');

        // Persisted raw fields never carry it.
        $this->assertStringNotContainsString('SECRET-PIN-12345', json_encode($transaction->fresh()->toArray()));

        // No log line carries it either.
        Log::shouldNotHaveReceived('info', fn ($message, $context = []) => str_contains(json_encode($context), 'SECRET-PIN-12345'));
        Log::shouldNotHaveReceived('error', fn ($message, $context = []) => str_contains(json_encode($context), 'SECRET-PIN-12345'));
    }

    // ── Callbacks ────────────────────────────────────────────────────────

    public function test_the_callback_log_carries_field_names_but_never_values(): void
    {
        [, $transaction] = $this->pendingTransaction('mock', total: 500_000);
        Log::spy();

        app(PaymentService::class)->handleCallback([
            'Authority' => $transaction->authority,
            'CardNumber' => '6037-99**-****-4321',
            'SessionToken' => 'sess-abcdef-secret',
        ], 'mock');

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) {
                if ($message !== 'Payment callback received') {
                    return false;
                }

                $encoded = json_encode($context);

                return str_contains($encoded, 'CardNumber')
                    && ! str_contains($encoded, '4321')
                    && ! str_contains($encoded, 'sess-abcdef-secret');
            })
            ->once();
    }

    public function test_a_customer_cannot_confirm_someone_elses_mock_payment(): void
    {
        [$order, $transaction] = $this->pendingTransaction('mock', total: 500_000);
        $stranger = User::create(['full_name' => 'دیگری', 'mobile' => '09120000099', 'status' => 'active'])->fresh();

        $this->actingAs($stranger)
            ->post(route('payment.mock.confirm.post', $transaction->authority), ['outcome' => 'pay'])
            ->assertForbidden();

        $this->assertNull(MockGateway::outcome($transaction->authority), 'no outcome may be recorded by a stranger');

        // And the owner's own later callback still cannot settle it on that forgery.
        $result = app(PaymentService::class)->handleCallback(['Authority' => $transaction->authority], 'mock');
        $this->assertFalse($result['success']);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_callback_naming_an_unknown_or_foreign_gateway_changes_nothing(): void
    {
        [$order, $transaction] = $this->pendingTransaction('mock', total: 500_000);
        MockGateway::recordOutcome($transaction->authority, 'paid');

        // The right authority presented under a different gateway is not found:
        // a transaction is only ever looked up within its own gateway.
        $result = app(PaymentService::class)->handleCallback(['Token' => $transaction->authority], 'pardakhtnovin');

        $this->assertFalse($result['success']);
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function order(int $total): Order
    {
        $user = User::create(['full_name' => 'پرداخت‌کننده', 'mobile' => '0912'.random_int(1000000, 9999999), 'status' => 'active']);

        return Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.random_int(100000, 999999),
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    /** @return array{0: Order, 1: PaymentTransaction} */
    private function pendingTransaction(string $gateway, int $total): array
    {
        $order = $this->order($total);

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'gateway' => $gateway,
            'amount' => $total,
            'status' => 'pending',
            'verification_state' => PaymentVerificationState::Unverified->value,
        ]);

        $transaction->update(['authority' => strtoupper($gateway).'_'.$transaction->id.'_'.random_int(1000, 9999)]);

        return [$order, $transaction->fresh()];
    }
}

/**
 * A gateway whose verification answer the test decides -- so PaymentService's
 * own branches (amount mismatch, Unknown) run for real. Registered through
 * config('rental.payment.gateways'), exactly as a real adapter is.
 */
class ScriptedGateway implements PaymentGatewayInterface
{
    public static ?GatewayVerification $verification = null;

    public function key(): string
    {
        return 'scripted';
    }

    public function request(PaymentTransaction $transaction, string $callbackUrl): GatewayRequestResult
    {
        return new GatewayRequestResult(success: true, authority: 'SCRIPTED_'.$transaction->id, redirectUrl: $callbackUrl);
    }

    public function parseCallback(array $params): GatewayCallback
    {
        return new GatewayCallback(authority: $params['Authority'] ?? null, params: $params);
    }

    public function verify(PaymentTransaction $transaction): GatewayVerification
    {
        return self::$verification ?? new GatewayVerification(state: PaymentVerificationState::Unknown);
    }

    public function status(PaymentTransaction $transaction): GatewayVerification
    {
        return $this->verify($transaction);
    }

    public function refund(PaymentTransaction $transaction, ?int $amountRial = null): GatewayRefund
    {
        return new GatewayRefund(success: false);
    }
}
