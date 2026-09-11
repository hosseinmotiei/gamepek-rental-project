<?php

namespace Tests\Feature;

use App\Enums\GuaranteeNoteStatus;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\GuaranteeInquiry;
use App\Models\GuaranteeNoteEvent;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\RentalDamagePayment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\GuaranteeNoteService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalClosureReadiness;
use App\Services\Rental\RentalDamageAssessmentService;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalSettlementService;
use App\Services\Wallet\WalletService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * CONFIRMED (C-59): closing a rental does not extinguish an unpaid damage.
 *
 * A GamePek-owned device's note is retained precisely BECAUSE the damage was
 * not paid, and that rental may still be closed. The customer can settle
 * afterwards, and the note then goes home to them.
 *
 * What that must NOT do is the whole point of this file: a post-close payment
 * is a financial record and nothing else. It must not move the lifecycle, open
 * an operation, move custody, re-block the device, or produce a second
 * settlement.
 */
class RentalPostClosureDamageTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $admin;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

    private GuaranteeNoteService $notes;

    private RentalDamageAssessmentService $damage;

    private RentalChainOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContractTemplateSeeder::class);
        $this->seed(UserSeeder::class);

        config()->set('verification.guarantee.required_inquiries', [
            GuaranteeInquiry::KIND_SAYAD_VALIDATE,
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH,
        ]);

        $this->app->bind(OtpProviderInterface::class, fn () => new class implements OtpProviderInterface
        {
            public function send(string $mobile): string
            {
                return json_encode(['code' => '13579']);
            }
        });

        $this->admin = User::where('mobile', '09100000001')->firstOrFail();
        $this->operations = app(RentalOperationService::class);
        $this->custody = app(DeviceCustodyService::class);
        $this->notes = app(GuaranteeNoteService::class);
        $this->damage = app(RentalDamageAssessmentService::class);
        $this->orchestrator = app(RentalChainOrchestrator::class);
    }

    // ── The confirmed path ───────────────────────────────────────────────

    public function test_a_closed_rental_with_a_retained_note_can_still_be_paid_and_the_note_returned(): void
    {
        [$customer, $application, $reservation] = $this->closedWithRetainedNote('09141000001', 'PC-A');

        $this->assertSame(RentalApplicationState::Closed, $application->state);
        $this->assertSame(GuaranteeNoteStatus::RetainedByGamePek, $this->notes->statusFor($application->id));

        $payment = $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-POST-CLOSE');

        $this->assertSame(3_000_000, $payment->amount);
        $this->assertSame(RentalDamageAssessmentService::PAID, $this->damage->statusFor($application->id)['status']);

        // Exactly one GamePek credit, linked to the payment.
        $wallet = app(WalletService::class)->gamePekWallet();
        $entries = WalletTransaction::where('wallet_id', $wallet->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame($entries->first()->id, $payment->wallet_transaction_id);
        $this->assertSame(3_000_000, (int) Wallet::whereKey($wallet->id)->value('balance'));

        // The note goes home, through the same append-only mechanism.
        $event = $this->notes->returnToCustomer($application->refresh(), $this->admin);

        $this->assertSame(GuaranteeNoteEvent::BASIS_DAMAGE_PAID, $event->basis);
        $this->assertSame(GuaranteeNoteStatus::ReturnedToCustomer, $this->notes->statusFor($application->id));

        // Append-only history: receive, retain, return -- nothing rewritten.
        $this->assertSame(
            [GuaranteeNoteEvent::RECEIVED, GuaranteeNoteEvent::RETAINED_BY_GAMEPEK, GuaranteeNoteEvent::RETURNED_TO_CUSTOMER],
            GuaranteeNoteEvent::where('rental_application_id', $application->id)->orderBy('id')->pluck('event')->all(),
        );

        // Traceable: actor, amount, reference and timestamp all recorded.
        $this->assertSame($this->admin->id, $payment->recorded_by_user_id);
        $this->assertSame('REF-POST-CLOSE', $payment->payment_reference);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame($this->admin->id, $event->recorded_by_user_id);

        $this->assertNotNull($customer);
        $this->assertNotNull($reservation);
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_a_post_close_payment_changes_nothing_about_the_rental_itself(): void
    {
        [, $application, $reservation] = $this->closedWithRetainedNote('09141000002', 'PC-B');

        $transitionsBefore = RentalApplicationTransition::where('rental_application_id', $application->id)->count();
        $operationsBefore = RentalOperation::where('rental_reservation_id', $reservation->id)->count();
        $custodyBefore = DeviceCustodyTransfer::where('rental_reservation_id', $reservation->id)->count();
        $settlementsBefore = RentalSettlement::count();
        $blockedBefore = $reservation->blockedUntil()->toDateString();

        $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-NO-SIDE-EFFECT');
        $this->notes->returnToCustomer($application->refresh(), $this->admin);

        $application = $application->fresh();

        // Lifecycle: still Closed, and not one new transition row.
        $this->assertSame(RentalApplicationState::Closed, $application->state);
        $this->assertSame($transitionsBefore, RentalApplicationTransition::where('rental_application_id', $application->id)->count());

        // No fabricated operation or custody movement.
        $this->assertSame($operationsBefore, RentalOperation::where('rental_reservation_id', $reservation->id)->count());
        $this->assertSame($custodyBefore, DeviceCustodyTransfer::where('rental_reservation_id', $reservation->id)->count());

        // No second settlement (a GamePek device has none at all).
        $this->assertSame($settlementsBefore, RentalSettlement::count());

        // The device does not start blocking again.
        $this->assertSame($blockedBefore, $reservation->fresh()->blockedUntil()->toDateString());
        $tomorrow = now()->addDay()->toDateString();
        $this->assertTrue(app(RentalAvailabilityService::class)->isFree($reservation->product_id, $tomorrow, $tomorrow));
    }

    public function test_closure_prerequisites_do_not_regress_after_a_post_close_payment(): void
    {
        [, $application] = $this->closedWithRetainedNote('09141000003', 'PC-C');

        $readiness = app(RentalClosureReadiness::class);
        $this->assertTrue($readiness->check($application)['ready'], 'the rental was closable when it was closed');

        $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-READINESS');

        $this->assertTrue($readiness->check($application->refresh())['ready'],
            'paying an outstanding damage can only improve readiness, never break it');

        $this->notes->returnToCustomer($application->refresh(), $this->admin);

        $this->assertTrue($readiness->check($application->refresh())['ready']);
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()
            ->where('code', OperationCustodyReconciler::CLOSED_WITHOUT_PREREQUISITES)->values()->all());
    }

    public function test_a_repeated_post_close_payment_credits_the_wallet_once(): void
    {
        [, $application] = $this->closedWithRetainedNote('09141000004', 'PC-D');

        $first = $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-ONCE');
        $second = $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-AGAIN');
        $third = $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-AGAIN');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, RentalDamagePayment::count());
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame('REF-ONCE', $first->fresh()->payment_reference, 'the first reference is not overwritten');
    }

    public function test_the_admin_route_is_idempotent_and_the_page_view_mutates_nothing(): void
    {
        [, $application] = $this->closedWithRetainedNote('09141000005', 'PC-E');

        $this->actingAs($this->admin->fresh());

        // Two posts, as a double click or a retried request would produce.
        $this->post(route('admin.rental-applications.damage-payment.store', $application), [
            'payment_reference' => 'REF-HTTP-1',
        ])->assertRedirect();
        $this->post(route('admin.rental-applications.damage-payment.store', $application), [
            'payment_reference' => 'REF-HTTP-2',
        ])->assertRedirect();

        $this->assertSame(1, RentalDamagePayment::count());
        $this->assertSame(1, WalletTransaction::count());

        $stateBefore = $application->fresh()->state;
        $walletBefore = WalletTransaction::count();

        // Refreshing either page derives nothing and writes nothing.
        $this->get(route('admin.rental-applications.show', $application))->assertOk();
        $this->get(route('admin.rental-applications.show', $application))->assertOk();

        $this->assertSame($stateBefore, $application->fresh()->state);
        $this->assertSame($walletBefore, WalletTransaction::count());
        $this->assertSame(1, RentalDamagePayment::count());
    }

    public function test_the_customer_page_neither_advances_a_closed_rental_nor_offers_a_payment_endpoint(): void
    {
        [$customer, $application] = $this->closedWithRetainedNote('09141000006', 'PC-F');

        // ->fresh(): strict mode refuses a missing attribute, and the session
        // guard reads remember_token off the authenticated model.
        $this->actingAs($customer->fresh());

        $response = $this->get(route('rental.applications.show', $application))->assertOk();

        // Closed stays closed even though the page calls advance().
        $this->assertSame(RentalApplicationState::Closed, $application->fresh()->state);

        // The customer is told the truth, in Persian, with no internal names
        // and no promise that paying reopens the rental.
        $response->assertSee('سفته شما نزد گیم‌پک است', false);
        $response->assertDontSee('retained_by_gamepek', false);
        $response->assertDontSee('RentalApplicationState', false);

        // There is no customer-facing damage payment endpoint at all, and the
        // admin one never reaches its controller for them: the admin-panel
        // middleware turns them away first.
        $this->post(route('admin.rental-applications.damage-payment.store', $application), [
            'payment_reference' => 'REF-CUSTOMER',
        ])->assertRedirect();

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_one_customer_cannot_pay_another_customers_damage(): void
    {
        [, $application] = $this->closedWithRetainedNote('09141000007', 'PC-G');

        $stranger = User::create(['full_name' => 'کاربر دیگر', 'mobile' => '09141999999', 'status' => 'active'])->fresh();

        // Refused by the admin-panel middleware before any controller runs.
        $this->actingAs($stranger)
            ->post(route('admin.rental-applications.damage-payment.store', $application), [
                'payment_reference' => 'REF-STRANGER',
            ])->assertRedirect();

        $this->actingAs($stranger)
            ->get(route('rental.applications.show', $application))
            ->assertForbidden();

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_note_already_handed_to_an_owner_is_never_payable_after_closure(): void
    {
        [, $application] = $this->closedWithTransferredNote('09141000008', '09149000008', 'PC-H');

        $this->assertSame(RentalApplicationState::Closed, $application->state);
        $this->assertSame(GuaranteeNoteStatus::TransferredToOwner, $this->notes->statusFor($application->id));

        try {
            $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-OWNER-NOTE');
            $this->fail('A note handed to the owner must not be payable through GamePek.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(RentalApplicationState::Closed, $application->fresh()->state);
    }

    public function test_a_cancelled_rental_stays_blocked_after_closure_rules_change(): void
    {
        [, $application] = $this->closedWithRetainedNote('09141000009', 'PC-I');

        // The confirmed rule opened CLOSED only. Everything else still refuses.
        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'cancelled']);

        try {
            $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-CANCELLED');
            $this->fail('A cancelled rental has no confirmed damage-payment rule.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_closed_rental_is_never_re_derived_onto_an_earlier_rung(): void
    {
        [, $application] = $this->closedWithRetainedNote('09141000010', 'PC-J');

        $this->assertSame(RentalApplicationState::Closed, $this->orchestrator->nextState($application->fresh()),
            'the pure predicate must answer Closed for a closed rental');

        $this->orchestrator->advance($application->fresh());
        $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-DERIVE');
        $this->orchestrator->advance($application->fresh());

        $this->assertSame(RentalApplicationState::Closed, $application->fresh()->state);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * A GamePek-owned rental, closed with an unpaid damage and its note
     * retained: the exact state C-59 is about.
     *
     * @return array{0: User, 1: RentalApplication, 2: RentalReservation}
     */
    private function closedWithRetainedNote(string $mobile, string $serial): array
    {
        [$customer, $application, $reservation, $inspection] = $this->inspectedReturn($mobile, null, $serial);

        $this->damage->record($inspection, $this->admin, 3_000_000);
        $this->notes->retainByGamePek($application->refresh(), $this->admin);

        $this->travel(3)->hours();
        $this->orchestrator->close($application->refresh(), $this->admin);

        return [$customer, $application->refresh(), $reservation->refresh()];
    }

    /**
     * The other side of the rule: an owner device whose note went to the owner.
     *
     * @return array{0: User, 1: RentalApplication, 2: RentalReservation}
     */
    private function closedWithTransferredNote(string $mobile, string $ownerMobile, string $serial): array
    {
        [$customer, $application, $reservation, $inspection] = $this->inspectedReturn($mobile, $ownerMobile, $serial);

        $this->damage->record($inspection, $this->admin, 3_000_000);
        $this->notes->transferToOwner($application->refresh(), $this->admin);

        $this->travel(3)->hours();

        $ownerReturn = $this->operations->openOwnerReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);
        $this->custody->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        $this->custody->recordReturnToOwner($ownerReturn->refresh(), $this->admin);

        app(RentalSettlementService::class)->finalize($application->refresh(), $this->admin);
        $this->orchestrator->close($application->refresh(), $this->admin);

        return [$customer, $application->refresh(), $reservation->refresh()];
    }

    /**
     * @return array{0: User, 1: RentalApplication, 2: RentalReservation, 3: RentalInspection}
     */
    private function inspectedReturn(string $mobile, ?string $ownerMobile, string $serial): array
    {
        $customer = User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);

        $application = $this->signedApplication($customer);
        $this->orchestrator->approve($application, $this->admin, null);
        $this->notes->receive($application->refresh(), $this->admin);

        $reservation = $application->reservation()->firstOrFail();
        $device = $this->device($application->product, $ownerMobile, $serial);

        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)->firstOrFail();
        $this->operations->attachDevice($pickup, $device, $this->admin);

        if ($ownerMobile !== null) {
            $this->operations->start($pickup->refresh(), $this->admin);
            $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
            $this->custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);
        }

        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        $inspection = app(RentalInspectionService::class)->record($return->refresh(), $this->admin, 'بررسی بازگشت');

        return [$customer, $application->refresh(), $reservation->refresh(), $inspection];
    }

    private function device(Product $product, ?string $ownerMobile, string $serial): Device
    {
        $devices = app(DeviceRegistrationService::class);

        $device = $ownerMobile === null
            ? $devices->registerForGamePek($product, $serial, [], $this->admin)
            : $devices->registerForOwner(
                $devices->ensureOwnerProfile(User::create(['full_name' => 'مالک '.$serial, 'mobile' => $ownerMobile, 'status' => 'active'])),
                $product,
                $serial,
            );

        return $devices->approve($device, $this->admin)->refresh();
    }
}
