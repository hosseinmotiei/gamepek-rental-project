<?php

namespace Tests\Feature;

use App\Enums\IdentityState;
use App\Models\Order;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Banking\BankAccountService;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\PaymentService;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The payment gate (C-13/C-14) and the reservation's position in the chain
 * (C-15/C-16).
 *
 * The defect this locks down: the chain used to return the highest rung whose
 * facts held, so an application with no identity record at all could reach
 * Paid. A customer really did pay with `identity: NONE`.
 */
class RentalPaymentGateTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('verification.identity.required_checks', ['shahkar', 'civil_registry']);

        $this->customer = User::create([
            'full_name' => 'مستأجر آزمایشی',
            'mobile' => '09121110001',
            'status' => 'active',
        ]);
    }

    // ── A. Payment gating ────────────────────────────────────────────────

    public function test_a_draft_application_cannot_pay(): void
    {
        $application = app(RentalReservationService::class)->openApplication($this->customer);

        $this->assertFalse(app(RentalChainOrchestrator::class)->canProceedToPayment($application));

        $this->actingAs($this->customer)
            ->postJson(route('rental.applications.pay', $application))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, Order::count());
    }

    public function test_an_application_with_incomplete_identity_cannot_pay(): void
    {
        // Submitted but never checked -- the state the old ladder ignored.
        app(IdentityVerificationService::class)->submit($this->customer, self::NATIONAL_CODE, '1995-03-21');

        $application = app(RentalReservationService::class)->openApplication($this->customer->fresh());

        $this->assertSame(
            'identity_not_verified',
            app(RentalChainOrchestrator::class)->paymentBlockedReason($application),
        );

        $this->actingAs($this->customer)
            ->postJson(route('rental.applications.pay', $application))
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_an_application_with_failed_identity_cannot_pay(): void
    {
        $identity = app(IdentityVerificationService::class)
            ->submit($this->customer, self::NATIONAL_CODE, '1995-03-21');

        app(IdentityVerificationService::class)->reject($identity, 'test rejection');

        $this->assertNotSame(IdentityState::Verified, $identity->refresh()->state);

        $application = app(RentalReservationService::class)->openApplication($this->customer->fresh());

        $this->actingAs($this->customer)
            ->postJson(route('rental.applications.pay', $application))
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_a_verified_identity_without_a_bank_account_cannot_pay(): void
    {
        $identity = app(IdentityVerificationService::class)
            ->submit($this->customer, self::NATIONAL_CODE, '1995-03-21');
        $identity->loadMissing('user');
        app(IdentityVerificationService::class)->runShahkar($identity);
        app(IdentityVerificationService::class)->runCivilRegistry($identity);

        $this->assertSame(IdentityState::Verified, $identity->refresh()->state);

        $application = app(RentalReservationService::class)->openApplication($this->customer->fresh());

        $this->assertSame(
            'bank_missing',
            app(RentalChainOrchestrator::class)->paymentBlockedReason($application),
        );

        $this->actingAs($this->customer)
            ->postJson(route('rental.applications.pay', $application))
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_an_unverified_bank_account_cannot_pay(): void
    {
        $identity = app(IdentityVerificationService::class)
            ->submit($this->customer, self::NATIONAL_CODE, '1995-03-21');
        $identity->loadMissing('user');
        app(IdentityVerificationService::class)->runShahkar($identity);
        app(IdentityVerificationService::class)->runCivilRegistry($identity);

        // Added but never verified.
        app(BankAccountService::class)->add($this->customer, 'card', '6037997599999993');

        $application = app(RentalReservationService::class)->openApplication($this->customer->fresh());

        $this->assertSame(
            'bank_not_verified',
            app(RentalChainOrchestrator::class)->paymentBlockedReason($application),
        );

        $this->actingAs($this->customer)
            ->postJson(route('rental.applications.pay', $application))
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_full_prerequisites_allow_payment(): void
    {
        $application = $this->reservedApplication($this->customer);

        $this->assertTrue(app(RentalChainOrchestrator::class)->canProceedToPayment($application));

        $this->actingAs($this->customer->fresh())
            ->postJson(route('rental.applications.pay', $application))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, Order::count());
    }

    public function test_a_direct_http_post_cannot_bypass_the_gate(): void
    {
        // No frontend involved: a raw POST straight at the route, exactly the
        // way the original defect was demonstrated.
        $application = app(RentalReservationService::class)->openApplication($this->customer);

        $this->actingAs($this->customer)
            ->post(route('rental.applications.pay', $application))
            ->assertRedirect();

        $this->assertSame(0, Order::count());
        $this->assertNull($application->fresh()->order_id);
    }

    public function test_another_customer_cannot_pay_for_someone_elses_application(): void
    {
        $application = $this->reservedApplication($this->customer);

        $intruder = User::create([
            'full_name' => 'مهاجم', 'mobile' => '09121119999', 'status' => 'active',
        ]);

        $this->actingAs($intruder)
            ->postJson(route('rental.applications.pay', $application))
            ->assertForbidden();

        $this->assertSame(0, Order::count());
    }

    public function test_the_gate_never_leaks_internal_state_names(): void
    {
        $application = app(RentalReservationService::class)->openApplication($this->customer);

        $response = $this->actingAs($this->customer)
            ->postJson(route('rental.applications.pay', $application))
            ->assertStatus(422);

        $body = $response->getContent();

        foreach (['identity_not_verified', 'bank_missing', 'selection_missing', 'Exception'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    // ── B. Reservation timing ────────────────────────────────────────────

    public function test_opening_an_application_creates_no_reservation(): void
    {
        app(RentalReservationService::class)->openApplication($this->customer);

        $this->assertSame(0, RentalReservation::count());
    }

    public function test_completing_kyc_creates_no_reservation(): void
    {
        $this->completeKyc($this->customer);
        app(RentalReservationService::class)->openApplication($this->customer->fresh());

        $this->assertSame(0, RentalReservation::count());
    }

    public function test_selecting_dates_creates_no_reservation(): void
    {
        $this->reservedApplication($this->customer);

        $this->assertSame(0, RentalReservation::count());
    }

    public function test_initiating_payment_creates_no_reservation(): void
    {
        $application = $this->reservedApplication($this->customer);

        $this->actingAs($this->customer->fresh())
            ->postJson(route('rental.applications.pay', $application))
            ->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame(0, RentalReservation::count(), 'payment_pending must not block inventory');
    }

    public function test_a_failed_payment_creates_no_reservation(): void
    {
        $application = $this->reservedApplication($this->customer);

        $this->actingAs($this->customer->fresh())
            ->postJson(route('rental.applications.pay', $application))
            ->assertOk();

        $transaction = $application->refresh()->order->paymentTransactions()->firstOrFail();
        MockGateway::recordOutcome($transaction->authority, 'cancelled');

        app(PaymentService::class)
            ->handleCallback(['Authority' => $transaction->authority], 'mock');

        $this->assertNotSame('paid', $application->refresh()->order->payment_status);
        $this->assertSame(0, RentalReservation::count());
    }

    public function test_a_verified_payment_creates_exactly_one_reservation_and_a_replay_creates_no_second(): void
    {
        $application = $this->reservedApplication($this->customer);

        $this->actingAs($this->customer->fresh())
            ->postJson(route('rental.applications.pay', $application))
            ->assertOk();

        $transaction = $application->refresh()->order->paymentTransactions()->firstOrFail();
        MockGateway::recordOutcome($transaction->authority, 'paid');

        app(PaymentService::class)
            ->handleCallback(['Authority' => $transaction->authority], 'mock');

        $this->assertSame(1, RentalReservation::count());

        // Replay the callback twice more.
        app(PaymentService::class)
            ->handleCallback(['Authority' => $transaction->authority], 'mock');
        app(PaymentService::class)
            ->handleCallback(['Authority' => $transaction->authority], 'mock');

        $this->assertSame(1, RentalReservation::count(), 'a replayed callback must not double-book');
    }

    public function test_materialising_twice_is_idempotent(): void
    {
        $application = $this->paidApplication($this->customer);

        $first = $application->refresh()->reservation;
        $this->assertNotNull($first);

        $second = app(RentalReservationService::class)->materialiseAfterPayment($application->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RentalReservation::count());
    }

    public function test_an_abandoned_application_never_blocks_inventory(): void
    {
        $application = $this->reservedApplication($this->customer);

        // Abandoned right here: selection made, never paid. C-17 lets the row
        // live forever; it must simply never take the device off the market.
        $this->assertSame(0, RentalReservation::count());

        $free = app(RentalAvailabilityService::class)->isFree(
            $application->product_id,
            $application->selected_start_date->toDateString(),
            $application->selected_end_date->toDateString(),
        );

        $this->assertTrue($free, 'an abandoned application must not block its dates');
    }

    public function test_a_rejected_application_never_blocks_inventory(): void
    {
        $application = $this->reservedApplication($this->customer);

        $admin = User::create(['full_name' => 'مدیر', 'mobile' => '09121118888', 'status' => 'active']);
        app(RentalChainOrchestrator::class)->reject($application, $admin, 'audit test');

        $free = app(RentalAvailabilityService::class)->isFree(
            $application->product_id,
            $application->selected_start_date->toDateString(),
            $application->selected_end_date->toDateString(),
        );

        $this->assertTrue($free, 'a rejected application must not block its dates');
    }
}
