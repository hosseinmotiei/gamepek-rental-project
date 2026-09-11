<?php

namespace Tests\Feature;

use App\Enums\GuaranteeNoteStatus;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Models\AuditEvent;
use App\Models\Device;
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
use App\Models\RentalSettlementCredit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\GuaranteeNoteService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalClosureReadiness;
use App\Services\Rental\RentalDamageAssessmentService;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalSettlementService;
use App\Services\Wallet\WalletService;
use App\Support\Rental\SettlementSplit;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Close-out: promissory note, damage payment, owner settlement credit and
 * Returned -> Closed, under the confirmed rules.
 *
 * The three confirmed scenarios run end to end: no damage; damage paid;
 * damage unpaid with the note handed to the owner. Around them: every
 * duplicate, contradiction, premature step and authorization boundary.
 */
class RentalCloseoutTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $admin;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

    private GuaranteeNoteService $notes;

    private RentalDamageAssessmentService $damage;

    private RentalSettlementService $settlements;

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
        $this->settlements = app(RentalSettlementService::class);
        $this->orchestrator = app(RentalChainOrchestrator::class);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function operation(RentalReservation $reservation, RentalOperationType $type): RentalOperation
    {
        return RentalOperation::where('rental_reservation_id', $reservation->id)->where('type', $type->value)->firstOrFail();
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

    /**
     * Approved -> note received -> delivered -> returned -> inspected.
     * Owner device unless $ownerMobile is null.
     */
    private function inspectedReturn(string $mobile, ?string $ownerMobile, string $serial): array
    {
        $customer = $this->customer($mobile);
        $application = $this->signedApplication($customer);
        $this->orchestrator->approve($application, $this->admin, null);
        $this->notes->receive($application->refresh(), $this->admin);

        $reservation = $application->reservation()->firstOrFail();
        $device = $this->device($application->product, $ownerMobile, $serial);

        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
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

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh(), $inspection];
    }

    private function assess(RentalInspection $inspection, int $amount): void
    {
        $this->damage->record($inspection, $this->admin, $amount);
    }

    /** Window over + device home with its owner. */
    private function ownerLegDone(RentalReservation $reservation): void
    {
        $this->travel(3)->hours();

        $ownerReturn = $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);
        $this->custody->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        $this->custody->recordReturnToOwner($ownerReturn->refresh(), $this->admin);
    }

    private function ownerWalletBalance(Device $device): int
    {
        return (int) Wallet::where('user_id', $device->owner->user_id)->value('balance');
    }

    private function assertClosedOnce(RentalApplication $application): void
    {
        $this->assertSame(RentalApplicationState::Closed, $application->refresh()->state);
        $this->assertSame(1, RentalApplicationTransition::where('rental_application_id', $application->id)
            ->where('to_state', RentalApplicationState::Closed->value)->count());
    }

    // ── The three confirmed scenarios ────────────────────────────────────

    public function test_scenario_one_no_damage_closes_with_one_owner_credit_and_the_note_returned(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170006001', '09170006002', 'SC1-001');

        $this->assess($inspection, 0);
        $this->notes->returnToCustomer($application, $this->admin);
        $this->ownerLegDone($reservation);

        $credit = $this->settlements->finalize($application, $this->admin);
        $this->orchestrator->close($application, $this->admin);

        $expected = SettlementSplit::of((int) $reservation->rental_total);
        $this->assertSame($expected->ownerShare, $credit->amount);
        $this->assertSame($expected->ownerShare, $this->ownerWalletBalance($device));
        $this->assertSame(GuaranteeNoteStatus::ReturnedToCustomer, $this->notes->statusFor($application->id));
        $this->assertClosedOnce($application);
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_scenario_two_paid_damage_returns_the_note_and_closes(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170006003', '09170006004', 'SC2-001');

        $this->assess($inspection, 300_000);
        $payment = $this->damage->recordPayment($application, $this->admin, 'RCPT-771');
        $this->notes->returnToCustomer($application, $this->admin);
        $this->ownerLegDone($reservation);
        $this->settlements->finalize($application, $this->admin);
        $this->orchestrator->close($application, $this->admin);

        $this->assertSame(300_000, $payment->amount);
        $this->assertSame(GuaranteeNoteEvent::BASIS_DAMAGE_PAID,
            GuaranteeNoteEvent::where('event', GuaranteeNoteEvent::RETURNED_TO_CUSTOMER)->value('basis'));
        // Damage money is paid directly: it credits no wallet. Only the
        // owner's settlement share moved.
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(SettlementSplit::of((int) $reservation->rental_total)->ownerShare, $this->ownerWalletBalance($device));
        $this->assertClosedOnce($application);
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_scenario_three_unpaid_damage_hands_the_note_to_the_owner_and_closes(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170006005', '09170006006', 'SC3-001');

        $this->assess($inspection, 800_000);
        $event = $this->notes->transferToOwner($application, $this->admin, 'مشتری پرداخت نکرد');
        $this->ownerLegDone($reservation);
        $this->settlements->finalize($application, $this->admin);
        $this->orchestrator->close($application, $this->admin);

        // Owner, device and assessment are derived, never supplied.
        $this->assertSame($device->owner_id, $event->owner_id);
        $this->assertSame($device->id, $event->device_id);
        $this->assertSame(GuaranteeNoteStatus::TransferredToOwner, $this->notes->statusFor($application->id));
        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertClosedOnce($application);
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    // ── Promissory note ──────────────────────────────────────────────────

    public function test_the_note_is_not_returned_before_the_damage_outcome_is_recorded(): void
    {
        [, $application] = $this->inspectedReturn('09170006007', '09170006008', 'NOTE-001');

        $this->expectException(\RuntimeException::class);
        $this->notes->returnToCustomer($application, $this->admin);
    }

    public function test_the_note_is_not_returned_while_damage_is_unpaid(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006009', '09170006010', 'NOTE-002');
        $this->assess($inspection, 100_000);

        $this->expectException(\RuntimeException::class);
        $this->notes->returnToCustomer($application, $this->admin);
    }

    public function test_the_note_is_not_transferred_when_there_is_no_unpaid_damage(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006011', '09170006012', 'NOTE-003');
        $this->assess($inspection, 0);

        $this->expectException(\RuntimeException::class);
        $this->notes->transferToOwner($application, $this->admin);
    }

    public function test_a_returned_note_can_never_also_be_transferred_and_repeats_are_idempotent(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006013', '09170006014', 'NOTE-004');
        $this->assess($inspection, 0);

        $first = $this->notes->returnToCustomer($application, $this->admin);
        $again = $this->notes->returnToCustomer($application, $this->admin);
        $this->assertSame($first->id, $again->id);

        try {
            $this->notes->transferToOwner($application, $this->admin);
            $this->fail('A returned note cannot also go to the owner.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, GuaranteeNoteEvent::whereNotNull('final_marker')->count());
    }

    public function test_the_database_refuses_a_second_final_note_event(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170006015', '09170006016', 'NOTE-005');
        $this->assess($inspection, 0);
        $returned = $this->notes->returnToCustomer($application, $this->admin);

        $this->expectException(QueryException::class);
        DB::table('guarantee_note_events')->insert([
            'guarantee_id' => $returned->guarantee_id, 'rental_application_id' => $application->id,
            'event' => 'transferred_to_owner', 'final_marker' => 1, 'owner_id' => $device->owner_id,
            'rental_damage_assessment_id' => $returned->rental_damage_assessment_id,
            'recorded_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_a_note_that_was_never_received_cannot_be_resolved(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006017', '09170006018', 'NOTE-006');
        $this->assess($inspection, 0);
        DB::table('guarantee_note_events')->where('event', 'received')->delete();

        $this->expectException(\RuntimeException::class);
        $this->notes->returnToCustomer($application, $this->admin);
    }

    public function test_a_gamepek_owned_device_has_no_owner_to_receive_the_note(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006019', null, 'NOTE-007');
        $this->assess($inspection, 200_000);

        $this->expectException(\RuntimeException::class);
        $this->notes->transferToOwner($application, $this->admin);
    }

    // ── Damage payment ───────────────────────────────────────────────────

    public function test_a_damage_payment_is_recorded_once_for_exactly_the_assessed_amount(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006020', '09170006021', 'PAY-001');
        $this->assess($inspection, 250_000);

        $first = $this->damage->recordPayment($application, $this->admin, 'RCPT-1');
        $again = $this->damage->recordPayment($application, $this->admin, 'RCPT-2');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, RentalDamagePayment::count());
        $this->assertSame(250_000, $first->amount);
        $this->assertSame(RentalDamageAssessmentService::PAID, $this->damage->statusFor($application->id)['status']);
        $this->assertTrue(AuditEvent::forAction('damage_payment.recorded')->exists());
    }

    public function test_a_paid_damage_cannot_be_re_assessed_and_zero_damage_cannot_be_paid(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006022', '09170006023', 'PAY-002');
        $this->assess($inspection, 0);

        try {
            $this->damage->recordPayment($application, $this->admin, 'RCPT-0');
            $this->fail('Nothing is owed when no damage was found.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assess($inspection, 50_000);
        $this->damage->recordPayment($application, $this->admin, 'RCPT-3');

        $this->expectException(\RuntimeException::class);
        $this->assess($inspection, 999_999);
    }

    public function test_an_unpaid_damage_is_never_treated_as_paid(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006024', '09170006025', 'PAY-003');
        $this->assess($inspection, 70_000);

        $this->assertSame(RentalDamageAssessmentService::UNPAID, $this->damage->statusFor($application->id)['status']);
        $items = collect(app(RentalClosureReadiness::class)->check($application)['items'])->pluck('status', 'key');
        $this->assertSame('missing', $items['damage_resolution']);
    }

    public function test_no_damage_payment_is_taken_once_the_note_went_to_the_owner(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006026', '09170006027', 'PAY-004');
        $this->assess($inspection, 90_000);
        $this->notes->transferToOwner($application, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->damage->recordPayment($application, $this->admin, 'RCPT-LATE');
    }

    // ── Settlement finalization ──────────────────────────────────────────

    public function test_settlement_uses_the_rental_price_only_and_credits_the_owner_exactly_once(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170006028', '09170006029', 'STL-001');
        $this->assess($inspection, 0);
        $this->ownerLegDone($reservation);

        $first = $this->settlements->finalize($application, $this->admin);
        $second = $this->settlements->finalize($application, $this->admin);

        $settlement = RentalSettlement::firstOrFail();
        // Rental price only: the delivery fee is not in the gross.
        $this->assertSame((int) $reservation->rental_total, $settlement->gross_amount);
        $this->assertGreaterThan(0, (int) $reservation->delivery_fee);
        $this->assertSame($settlement->gross_amount, $settlement->gamepek_share + $settlement->owner_share);
        $this->assertSame(intdiv($settlement->gross_amount * 35, 100), $settlement->gamepek_share);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RentalSettlementCredit::count());
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame($settlement->owner_share, $this->ownerWalletBalance($device));
        $this->assertSame('settlement:'.$settlement->reference_number.':owner', WalletTransaction::firstOrFail()->idempotency_key);
        $this->assertTrue($settlement->refresh()->isCredited());
    }

    public function test_settlement_is_not_credited_before_its_prerequisites(): void
    {
        [, $application, $reservation, , $inspection] = $this->inspectedReturn('09170006030', '09170006031', 'STL-002');
        $this->assess($inspection, 0);

        // Window still open and device not home.
        try {
            $this->settlements->finalize($application, $this->admin);
            $this->fail('No credit before the settlement point.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->travel(3)->hours();

        try {
            $this->settlements->finalize($application, $this->admin);
            $this->fail('No credit before the device is back with its owner.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, RentalSettlementCredit::count());
        $this->assertTrue(AuditEvent::forAction('settlement.finalize_denied')->exists());
    }

    public function test_a_gamepek_owned_rental_is_never_settled_to_an_owner(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170006032', null, 'STL-003');
        $this->assess($inspection, 0);
        $this->travel(3)->hours();

        $this->expectException(\RuntimeException::class);

        try {
            $this->settlements->finalize($application, $this->admin);
        } finally {
            $this->assertSame(0, WalletTransaction::count());
        }
    }

    // ── Closure ──────────────────────────────────────────────────────────

    public function test_closure_is_refused_while_any_prerequisite_is_missing(): void
    {
        [, $application, $reservation, , $inspection] = $this->inspectedReturn('09170006033', '09170006034', 'CLS-101');
        $this->assess($inspection, 0);
        $this->notes->returnToCustomer($application, $this->admin);
        $this->ownerLegDone($reservation);

        // Everything but the owner credit.
        try {
            $this->orchestrator->close($application, $this->admin);
            $this->fail('A rental must not close before the owner is credited.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $denied = AuditEvent::forAction('rental_application.closure_denied')->firstOrFail();
        $this->assertSame(['settlement'], $denied->context['missing']);

        $this->settlements->finalize($application, $this->admin);
        $this->orchestrator->close($application, $this->admin);
        $this->orchestrator->close($application, $this->admin);

        $this->assertClosedOnce($application);
    }

    public function test_a_rental_that_is_not_returned_cannot_be_closed(): void
    {
        $application = $this->signedApplication($this->customer('09170006035'));
        $this->orchestrator->approve($application, $this->admin, null);

        $this->expectException(\RuntimeException::class);
        $this->orchestrator->close($application->refresh(), $this->admin);
    }

    public function test_the_reconciler_detects_impossible_finance_and_closure_states(): void
    {
        [, $application, $reservation, , $inspection] = $this->inspectedReturn('09170006036', '09170006037', 'REC-201');
        $this->assess($inspection, 0);
        $this->ownerLegDone($reservation);
        $credit = $this->settlements->finalize($application, $this->admin);
        $settlement = RentalSettlement::firstOrFail();

        // Out-of-band writes the services never make.
        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'closed']);
        DB::table('wallet_transactions')->where('id', $credit->wallet_transaction_id)->update(['amount' => 1]);
        DB::table('wallet_transactions')->insert([
            'wallet_id' => WalletTransaction::findOrFail($credit->wallet_transaction_id)->wallet_id,
            'reference_number' => 'WAL-FORGED-1', 'type' => 'credit', 'amount' => 5, 'balance_after' => 5,
            'reason' => 'forged', 'context' => json_encode(['settlement_reference' => $settlement->reference_number]),
        ]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::CLOSED_WITHOUT_PREREQUISITES, $codes);
        $this->assertContains(OperationCustodyReconciler::SETTLEMENT_AMOUNT_MISMATCH, $codes);
        $this->assertContains(OperationCustodyReconciler::DUPLICATE_OWNER_CREDIT, $codes);
        // Read-only: nothing was repaired.
        $this->assertSame('closed', DB::table('rental_applications')->where('id', $application->id)->value('state'));
    }

    public function test_the_reconciler_detects_a_credit_record_without_ledger_evidence(): void
    {
        [, $application, $reservation, , $inspection] = $this->inspectedReturn('09170006038', '09170006039', 'REC-202');
        $this->assess($inspection, 0);
        $this->ownerLegDone($reservation);
        $credit = $this->settlements->finalize($application, $this->admin);

        // Point the credit at a debit entry that is not the owner's credit.
        $foreign = app(WalletService::class)->credit($this->customer('09170006040'), 10, 'unrelated');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('rental_settlement_credits')->where('id', $credit->id)->update(['wallet_transaction_id' => $foreign->id]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::SETTLEMENT_CREDIT_WITHOUT_LEDGER, $codes);
    }

    // ── Authorization and visibility ─────────────────────────────────────

    public function test_close_out_actions_require_manage_rental_applications(): void
    {
        [$customer, $application] = $this->inspectedReturn('09170006041', '09170006042', 'AUTH-001');
        $staff = User::create(['full_name' => 'کارمند', 'mobile' => '09170006043', 'email' => 'pm.closeout@test.local', 'status' => 'active']);
        $staff->syncRoles(['product_manager']);

        foreach (['guarantee-note.return', 'guarantee-note.transfer', 'damage-payment.store', 'settlement.finalize', 'close'] as $name) {
            $url = route('admin.rental-applications.'.$name, $application);
            $this->actingAs($customer->fresh())->post($url, ['payment_reference' => 'x'])->assertRedirect(route('admin.login'));
            $this->actingAs($staff)->post($url, ['payment_reference' => 'x'])->assertForbidden();
        }

        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertSame(1, GuaranteeNoteEvent::count());
    }

    public function test_posted_identifiers_cannot_redirect_a_note_transfer(): void
    {
        [, $application, , $device, $inspection] = $this->inspectedReturn('09170006044', '09170006045', 'AUTH-002');
        $this->assess($inspection, 60_000);
        $stranger = $this->device($application->product, '09170006046', 'AUTH-002-B');

        $this->actingAs($this->admin)->post(route('admin.rental-applications.guarantee-note.transfer', $application), [
            'owner_id' => $stranger->owner_id, 'device_id' => $stranger->id,
        ])->assertSessionHas('success');

        $event = GuaranteeNoteEvent::where('event', GuaranteeNoteEvent::TRANSFERRED_TO_OWNER)->firstOrFail();
        $this->assertSame($device->owner_id, $event->owner_id);
        $this->assertSame($device->id, $event->device_id);
    }

    public function test_the_customer_sees_their_damage_and_note_status_but_no_internal_detail(): void
    {
        [$customer, $application, , , $inspection] = $this->inspectedReturn('09170006047', '09170006048', 'VIS-001');
        $this->damage->record($inspection, $this->admin, 420_000, 'یادداشت محرمانه کارشناس', 'IMG-SECRET');

        $this->actingAs($customer)->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertSee(persian_number(number_format(420_000)))
            ->assertSee('پرداخت نشده')
            ->assertSee('نزد گیم‌پک')
            ->assertDontSee('یادداشت محرمانه کارشناس')
            ->assertDontSee('IMG-SECRET')
            ->assertDontSee('transferred_to_owner')
            ->assertDontSee('held_by_gamepek');
    }

    public function test_only_the_owner_of_the_rental_sees_their_credit_and_the_transferred_note(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170006049', '09170006050', 'VIS-002');
        $this->assess($inspection, 30_000);
        $this->notes->transferToOwner($application, $this->admin);
        $this->ownerLegDone($reservation);
        $this->settlements->finalize($application, $this->admin);
        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);

        $this->actingAs($device->owner->user)->get(route('owner.operations.show', $pickup))
            ->assertOk()
            ->assertSee('به کیف پول مالک واریز شد')
            ->assertSee('سفته مشتری این اجاره');

        $stranger = $this->device($application->product, '09170006051', 'VIS-002-B')->owner->user;
        $this->actingAs($stranger)->get(route('owner.operations.show', $pickup))->assertForbidden();
    }

    public function test_viewing_close_out_pages_changes_no_state(): void
    {
        [$customer, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170006052', '09170006053', 'VIS-003');
        $this->assess($inspection, 0);
        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);

        $snapshot = fn () => [
            RentalApplication::findOrFail($application->id)->state,
            RentalApplicationTransition::count(),
            GuaranteeNoteEvent::count(),
            RentalSettlement::count(),
            RentalSettlementCredit::count(),
            WalletTransaction::count(),
        ];
        $before = $snapshot();

        $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rental-applications.show', $application))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.operations.reconciliation'))->assertOk();
        $this->actingAs($device->owner->user)->get(route('owner.operations.show', $pickup))->assertOk();

        $this->assertSame($before, $snapshot());
    }
}
