<?php

namespace Tests\Feature;

use App\Enums\GuaranteeNoteStatus;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\GuaranteeNoteEvent;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\RentalDamagePayment;
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
use App\Services\Rental\RentalDamageAssessmentService;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalReservationService;
use App\Services\Rental\RentalSettlementService;
use App\Services\RentalPricingService;
use App\Support\Rental\SettlementSplit;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The owner's confirmed finance decisions, enforced end to end:
 * full rental payment, no cash deposit, rental_total as the only settlement
 * base, paid damage to the GamePek wallet, GamePek-owned damage, and a note
 * that stays held when a rental is cancelled after it was received.
 */
class RentalFinanceAlignmentTest extends TestCase
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

    /** Approved -> note received -> delivered -> returned -> inspected. */
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

    private function ownerLegDone(RentalReservation $reservation): void
    {
        $this->travel(3)->hours();

        $ownerReturn = $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);
        $this->custody->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        $this->custody->recordReturnToOwner($ownerReturn->refresh(), $this->admin);
    }

    private function gamePekBalance(): int
    {
        return (int) Wallet::where('purpose', Wallet::PURPOSE_GAMEPEK)->value('balance');
    }

    // ── 1. Full payment before a paid reservation ────────────────────────

    public function test_no_reservation_exists_before_full_payment_and_a_replayed_payment_creates_one(): void
    {
        $unpaid = $this->reservedApplication($this->customer('09170007001'), $this->uniqueNationalCode());

        // Selection recorded, nothing paid: nothing is reserved or blocked.
        $this->assertNull($unpaid->reservation()->first());
        $this->assertFalse(RentalReservation::blocking()->exists());

        $paid = $this->paidApplication($this->customer('09170007002'), $this->uniqueNationalCode());
        $reservation = $paid->reservation()->firstOrFail();

        // Full payment = rental + delivery; the legacy deposit figure is not in it.
        $this->assertSame((int) $reservation->rental_total + (int) $reservation->delivery_fee, (int) $reservation->payable_now);
        $this->assertSame('paid', $reservation->state->value);

        // A replayed gateway success materialises nothing new.
        app(RentalReservationService::class)->materialiseAfterPayment($paid->refresh());
        $this->assertSame(1, RentalReservation::where('rental_application_id', $paid->id)->count());
        $this->assertSame(1, RentalOperation::where('rental_reservation_id', $reservation->id)->count());
        $this->assertSame(0, WalletTransaction::count());
    }

    // ── 2-3. Settlement base: rental_total only, the owner's exact example ─

    public function test_the_owners_worked_example_prices_and_splits_exactly(): void
    {
        // 10 days x 1,000,000 + controller 10 x 100,000 + game 500,000
        // = 11,500,000; a 500,000 discount; 300,000 delivery.
        config()->set('rental.pricing.duration_discounts', [10 => 500_000 / 11_500_000]);

        $quote = app(RentalPricingService::class)->quote(1_000_000, 10, 100_000, true, 500_000, 300_000);

        $this->assertSame(11_500_000, $quote->subtotal);
        $this->assertSame(500_000, $quote->discount);
        $this->assertSame(11_000_000, $quote->rentalTotal);
        $this->assertSame(11_300_000, $quote->payableNow);

        $split = SettlementSplit::of($quote->rentalTotal);
        $this->assertSame(3_850_000, $split->gamepekShare);
        $this->assertSame(7_150_000, $split->ownerShare);
    }

    public function test_the_owner_is_credited_from_rental_total_never_the_delivery_fee(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170007003', '09170007004', 'ALN-001');
        DB::table('rental_reservations')->where('id', $reservation->id)
            ->update(['rental_total' => 11_000_000, 'delivery_fee' => 300_000, 'payable_now' => 11_300_000]);

        $this->damage->record($inspection, $this->admin, 0);
        $this->ownerLegDone($reservation);
        $this->settlements->finalize($application, $this->admin);

        $settlement = RentalSettlement::firstOrFail();
        $this->assertSame(11_000_000, $settlement->gross_amount);
        $this->assertSame(3_850_000, $settlement->gamepek_share);
        $this->assertSame(7_150_000, $settlement->owner_share);
        $this->assertSame(7_150_000, (int) Wallet::where('user_id', $device->owner->user_id)->value('balance'));
    }

    // ── 4-9. Damage payment -> GamePek wallet ────────────────────────────

    public function test_a_damage_payment_credits_the_gamepek_wallet_once_for_the_assessed_amount(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007005', '09170007006', 'ALN-002');
        $this->damage->record($inspection, $this->admin, 480_000);

        $first = $this->damage->recordPayment($application, $this->admin, 'RCPT-1');
        $again = $this->damage->recordPayment($application, $this->admin, 'RCPT-2');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(480_000, $this->gamePekBalance());
        $this->assertSame(1, WalletTransaction::count());

        $entry = WalletTransaction::findOrFail($first->wallet_transaction_id);
        $this->assertSame(480_000, $entry->amount);
        $this->assertSame('damage:'.$first->rental_damage_assessment_id.':gamepek', $entry->idempotency_key);
        $this->assertTrue(Wallet::findOrFail($entry->wallet_id)->isGamePek());
    }

    public function test_the_payment_amount_is_the_assessed_amount_whatever_is_posted(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007007', '09170007008', 'ALN-003');
        $this->damage->record($inspection, $this->admin, 150_000);

        $this->actingAs($this->admin)->post(route('admin.rental-applications.damage-payment.store', $application), [
            'payment_reference' => 'RCPT-9', 'amount' => 999_999_999,
        ])->assertSessionHas('success');

        $this->assertSame(150_000, RentalDamagePayment::firstOrFail()->amount);
        $this->assertSame(150_000, $this->gamePekBalance());
    }

    public function test_no_damage_payment_for_a_zero_assessment_or_after_the_note_went_to_the_owner(): void
    {
        [, $zero, , , $zeroInspection] = $this->inspectedReturn('09170007009', '09170007010', 'ALN-004');
        $this->damage->record($zeroInspection, $this->admin, 0);

        try {
            $this->damage->recordPayment($zero, $this->admin, 'RCPT-0');
            $this->fail('Nothing is owed for a zero assessment.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_no_damage_payment_after_the_note_was_transferred_to_the_owner(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007012', '09170007013', 'ALN-005');
        $this->damage->record($inspection, $this->admin, 90_000);
        $this->notes->transferToOwner($application, $this->admin);

        $this->actingAs($this->admin)->post(route('admin.rental-applications.damage-payment.store', $application), [
            'payment_reference' => 'RCPT-LATE',
        ])->assertSessionHas('error');

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, $this->gamePekBalance());
    }

    public function test_a_damage_payment_never_changes_the_owners_sixty_five_percent(): void
    {
        [, $application, $reservation, $device, $inspection] = $this->inspectedReturn('09170007014', '09170007015', 'ALN-006');
        $this->damage->record($inspection, $this->admin, 700_000);
        $this->damage->recordPayment($application, $this->admin, 'RCPT-3');
        $this->notes->returnToCustomer($application, $this->admin);
        $this->ownerLegDone($reservation);
        $this->settlements->finalize($application, $this->admin);

        $expected = SettlementSplit::of((int) $reservation->rental_total);
        $this->assertSame($expected->ownerShare, RentalSettlement::firstOrFail()->owner_share);
        $this->assertSame($expected->ownerShare, (int) Wallet::where('user_id', $device->owner->user_id)->value('balance'));
        // The damage is GamePek's in full, never split 35/65.
        $this->assertSame(700_000, $this->gamePekBalance());
    }

    // ── 10-11. GamePek-owned damage ──────────────────────────────────────

    public function test_a_gamepek_owned_device_with_unpaid_damage_keeps_the_note_and_closes(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007016', null, 'ALN-007');
        $this->damage->record($inspection, $this->admin, 400_000);

        // No owner exists to receive it.
        try {
            $this->notes->transferToOwner($application, $this->admin);
            $this->fail('A GamePek-owned device has no owner to receive the note.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->notes->retainByGamePek($application, $this->admin);
        $this->orchestrator->close($application, $this->admin);

        $this->assertSame(GuaranteeNoteStatus::RetainedByGamePek, $this->notes->statusFor($application->id));
        $this->assertSame(RentalApplicationState::Closed, $application->refresh()->state);
        $this->assertSame(0, RentalSettlement::count());
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_a_gamepek_owned_device_with_paid_damage_credits_gamepek_returns_the_note_and_closes(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007017', null, 'ALN-008');
        $this->damage->record($inspection, $this->admin, 220_000);
        $this->damage->recordPayment($application, $this->admin, 'RCPT-GP');
        $this->notes->returnToCustomer($application, $this->admin);
        $this->orchestrator->close($application, $this->admin);

        $this->assertSame(220_000, $this->gamePekBalance());
        $this->assertSame(RentalApplicationState::Closed, $application->refresh()->state);
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_retention_is_refused_for_an_owner_device(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007018', '09170007019', 'ALN-009');
        $this->damage->record($inspection, $this->admin, 50_000);

        $this->expectException(\RuntimeException::class);
        $this->notes->retainByGamePek($application, $this->admin);
    }

    // ── 12-16. Cancellation after the note was received ──────────────────

    public function test_a_rental_cancelled_after_note_receipt_keeps_the_note_held_and_moves_no_money(): void
    {
        $application = $this->signedApplication($this->customer('09170007020'));
        $this->orchestrator->approve($application, $this->admin, null);
        $this->notes->receive($application->refresh(), $this->admin);

        $this->orchestrator->cancel($application->refresh(), $this->admin, 'لغو');

        $this->assertSame(RentalApplicationState::Cancelled, $application->refresh()->state);
        $this->assertSame(GuaranteeNoteStatus::HeldByGamePek, $this->notes->statusFor($application->id));

        foreach (['returnToCustomer', 'transferToOwner', 'retainByGamePek'] as $method) {
            try {
                $this->notes->{$method}($application, $this->admin);
                $this->fail($method.' must not resolve a cancelled rental\'s note.');
            } catch (\RuntimeException) {
                // expected
            }
        }

        try {
            $this->settlements->finalize($application, $this->admin);
            $this->fail('A cancelled rental must not settle.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, GuaranteeNoteEvent::count());
        $this->assertSame(0, RentalSettlement::count());
        $this->assertSame(0, RentalSettlementCredit::count());
        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_the_promissory_note_is_never_money(): void
    {
        $application = $this->signedApplication($this->customer('09170007021'));
        $this->orchestrator->approve($application, $this->admin, null);
        $this->notes->receive($application->refresh(), $this->admin);

        $this->assertFalse(Schema::hasColumn('guarantee_note_events', 'amount'));
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, Wallet::count());
    }

    // ── Reconciler: the new financial invariants ─────────────────────────

    public function test_the_reconciler_detects_damage_receipts_without_or_mismatching_the_gamepek_credit(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007022', '09170007023', 'ALN-010');
        $this->damage->record($inspection, $this->admin, 60_000);
        $payment = $this->damage->recordPayment($application, $this->admin, 'RCPT-R');

        DB::table('wallet_transactions')->where('id', $payment->wallet_transaction_id)->update(['amount' => 1]);
        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();
        $this->assertContains(OperationCustodyReconciler::DAMAGE_PAYMENT_WALLET_MISMATCH, $codes);

        DB::table('rental_damage_payments')->where('id', $payment->id)->update(['wallet_transaction_id' => null]);
        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();
        $this->assertContains(OperationCustodyReconciler::DAMAGE_PAYMENT_WITHOUT_WALLET_CREDIT, $codes);
    }

    public function test_the_reconciler_detects_a_second_gamepek_credit_for_one_damage(): void
    {
        [, $application, , , $inspection] = $this->inspectedReturn('09170007024', '09170007025', 'ALN-011');
        $this->damage->record($inspection, $this->admin, 60_000);
        $payment = $this->damage->recordPayment($application, $this->admin, 'RCPT-D');

        DB::table('wallet_transactions')->insert([
            'wallet_id' => WalletTransaction::findOrFail($payment->wallet_transaction_id)->wallet_id,
            'reference_number' => 'WAL-FORGED-D', 'type' => 'credit', 'amount' => 60_000, 'balance_after' => 120_000,
            'reason' => 'forged', 'context' => json_encode(['rental_damage_assessment_id' => $payment->rental_damage_assessment_id]),
        ]);

        $this->assertContains(OperationCustodyReconciler::DUPLICATE_DAMAGE_WALLET_CREDIT,
            app(OperationCustodyReconciler::class)->findings()->pluck('code')->all());
    }

    public function test_the_reconciler_detects_cancelled_financial_effects_a_wrong_settlement_base_and_a_deposit_in_payment(): void
    {
        [, $application, $reservation, , $inspection] = $this->inspectedReturn('09170007026', '09170007027', 'ALN-012');
        $this->damage->record($inspection, $this->admin, 0);
        $this->ownerLegDone($reservation);
        $this->settlements->finalize($application, $this->admin);

        $settlement = RentalSettlement::firstOrFail();
        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'cancelled']);
        // Delivery fee leaked into the base (shares still sum, so only the base check can see it).
        $gross = $settlement->gross_amount + (int) $reservation->delivery_fee;
        $split = SettlementSplit::of($gross);
        DB::table('rental_settlements')->where('id', $settlement->id)
            ->update(['gross_amount' => $gross, 'gamepek_share' => $split->gamepekShare, 'owner_share' => $split->ownerShare]);
        DB::table('rental_reservations')->where('id', $reservation->id)
            ->update(['payable_now' => DB::raw('rental_total + delivery_fee + deposit_amount')]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::CANCELLED_RENTAL_FINANCIAL_EFFECT, $codes);
        $this->assertContains(OperationCustodyReconciler::SETTLEMENT_BASE_MISMATCH, $codes);
        $this->assertContains(OperationCustodyReconciler::DEPOSIT_IN_PAYMENT, $codes);
    }

    public function test_the_reconciler_detects_a_note_given_to_an_owner_for_gamepek_stock(): void
    {
        [, $application, , $device, $inspection] = $this->inspectedReturn('09170007028', '09170007029', 'ALN-013');
        $this->damage->record($inspection, $this->admin, 10_000);
        $event = $this->notes->transferToOwner($application, $this->admin);
        $gamePekDevice = $this->device($application->product, null, 'ALN-013-GP');

        DB::table('guarantee_note_events')->where('id', $event->id)->update(['device_id' => $gamePekDevice->id]);

        $this->assertContains(OperationCustodyReconciler::NOTE_TRANSFERRED_FOR_GAMEPEK_DEVICE,
            app(OperationCustodyReconciler::class)->findings()->pluck('code')->all());
    }

    // ── UI, GET purity, authorization ────────────────────────────────────

    public function test_customer_pages_show_payment_damage_and_note_status_without_deposit_or_internal_detail(): void
    {
        [$customer, $application, , , $inspection] = $this->inspectedReturn('09170007030', null, 'ALN-014');
        $this->damage->record($inspection, $this->admin, 330_000, 'یادداشت داخلی', 'IMG-INTERNAL');

        $this->actingAs($customer)->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertSee('مبلغ کامل پرداخت شده است')
            ->assertSee(persian_number(number_format(330_000)))
            ->assertSee('پرداخت نشده')
            ->assertSee('نزد گیم‌پک')
            ->assertSee('سفته فیزیکی')
            ->assertDontSee('ودیعه (')
            ->assertDontSee('یادداشت داخلی')
            ->assertDontSee('IMG-INTERNAL')
            ->assertDontSee('retained_by_gamepek');
    }

    public function test_rendering_pages_changes_no_state_and_retention_requires_the_right_permission(): void
    {
        [$customer, $application, , , $inspection] = $this->inspectedReturn('09170007031', null, 'ALN-015');
        $this->damage->record($inspection, $this->admin, 80_000);
        $this->damage->recordPayment($application, $this->admin, 'RCPT-V');

        $snapshot = fn () => [
            RentalApplication::findOrFail($application->id)->state,
            RentalApplicationTransition::count(),
            GuaranteeNoteEvent::count(),
            WalletTransaction::count(),
            RentalDamagePayment::count(),
        ];
        $before = $snapshot();

        $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rental-applications.show', $application))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.wallet.index'))->assertOk()->assertSee('کیف پول گیم‌پک');
        $this->actingAs($this->admin)->get(route('admin.operations.reconciliation'))->assertOk();

        $this->assertSame($before, $snapshot());

        $staff = User::create(['full_name' => 'کارمند', 'mobile' => '09170007032', 'email' => 'pm.align@test.local', 'status' => 'active']);
        $staff->syncRoles(['product_manager']);

        $url = route('admin.rental-applications.guarantee-note.retain', $application);
        $this->actingAs($customer->fresh())->post($url)->assertRedirect(route('admin.login'));
        $this->actingAs($staff)->post($url)->assertForbidden();
        $this->assertSame(GuaranteeNoteStatus::HeldByGamePek, $this->notes->statusFor($application->id));
    }
}
