<?php

namespace Tests\Feature;

use App\Enums\PaymentVerificationState;
use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\UnconfiguredGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(User $user, int $total = 500_000): Order
    {
        return Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.random_int(1000, 9999),
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    private function makeUser(string $mobile = '09120000010'): User
    {
        return User::create([
            'full_name' => 'کاربر پرداخت',
            'mobile' => $mobile,
            'status' => 'active',
        ]);
    }

    public function test_a_callback_alone_cannot_mark_an_order_paid(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);

        $service = app(PaymentService::class);
        $init = $service->initiatePayment($order);

        $this->assertTrue($init['success']);

        // The customer never passed through the confirmation page -- they just
        // hit the callback URL directly, which is exactly the attack the old
        // `Status=OK` mock allowed.
        $result = $service->handleCallback([
            'Authority' => $init['authority'],
            'Status' => 'OK',
        ], 'mock');

        $this->assertFalse($result['success']);

        // The point of the assertion: the order is NOT paid. It is marked
        // failed, the same as any other unverified attempt -- the forged
        // Status=OK bought nothing.
        $this->assertNotSame('paid', $order->fresh()->payment_status);
        $this->assertSame('failed', PaymentTransaction::firstOrFail()->status);
        $this->assertNull($order->fresh()->payment_tracking_code);
    }

    public function test_a_server_side_recorded_success_marks_the_order_paid_once(): void
    {
        $user = $this->makeUser('09120000011');
        $order = $this->makeOrder($user);

        $service = app(PaymentService::class);
        $init = $service->initiatePayment($order);

        // What the in-app confirmation page does, server-side.
        MockGateway::recordOutcome($init['authority'], 'paid');

        $first = $service->handleCallback(['Authority' => $init['authority']], 'mock');

        $this->assertTrue($first['success']);
        $this->assertNotNull($first['tracking_code']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($first['tracking_code'], $order->payment_tracking_code);

        $transaction = PaymentTransaction::firstOrFail();
        $this->assertSame('success', $transaction->status);
        $this->assertSame(PaymentVerificationState::Verified, $transaction->verification_state);
        $this->assertNotNull($transaction->verified_at);
    }

    public function test_replaying_the_callback_is_idempotent(): void
    {
        $user = $this->makeUser('09120000012');
        $order = $this->makeOrder($user);

        $service = app(PaymentService::class);
        $init = $service->initiatePayment($order);
        MockGateway::recordOutcome($init['authority'], 'paid');

        $first = $service->handleCallback(['Authority' => $init['authority']], 'mock');
        $second = $service->handleCallback(['Authority' => $init['authority']], 'mock');
        $third = $service->handleCallback(['Authority' => $init['authority']], 'mock');

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue($third['success']);
        $this->assertSame($first['tracking_code'], $second['tracking_code']);

        $this->assertSame(1, PaymentTransaction::where('status', 'success')->count());
        $this->assertSame(1, AuditEvent::forAction('order.paid')->count());
        $this->assertSame(1, AuditEvent::forAction('payment.verified')->count());
    }

    public function test_a_cancelled_payment_fails_the_transaction(): void
    {
        $user = $this->makeUser('09120000013');
        $order = $this->makeOrder($user);

        $service = app(PaymentService::class);
        $init = $service->initiatePayment($order);
        MockGateway::recordOutcome($init['authority'], 'cancelled');

        $result = $service->handleCallback(['Authority' => $init['authority']], 'mock');

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_the_mock_gateway_is_unreachable_outside_local_and_testing(): void
    {
        $registry = app(GatewayRegistry::class);

        $this->assertInstanceOf(MockGateway::class, $registry->for('mock'));

        app()->detectEnvironment(fn () => 'production');

        $this->assertInstanceOf(UnconfiguredGateway::class, $registry->for('mock'));
        $this->assertInstanceOf(UnconfiguredGateway::class, $registry->for('zarinpal'));
        $this->assertInstanceOf(UnconfiguredGateway::class, $registry->for('typo-gateway'));

        app()->detectEnvironment(fn () => 'testing');
    }

    public function test_every_audit_row_from_one_payment_shares_a_correlation_id(): void
    {
        $user = $this->makeUser('09120000014');
        $order = $this->makeOrder($user);

        $service = app(PaymentService::class);
        $init = $service->initiatePayment($order);
        MockGateway::recordOutcome($init['authority'], 'paid');
        $service->handleCallback(['Authority' => $init['authority']], 'mock');

        $this->assertGreaterThanOrEqual(3, AuditEvent::count());
        $this->assertCount(1, AuditEvent::pluck('correlation_id')->unique());
    }
}
