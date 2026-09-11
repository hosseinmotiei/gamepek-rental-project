<?php

namespace Tests\Feature;

use App\Enums\GuaranteeNoteStatus;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\GuaranteeNoteEvent;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalDamagePayment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
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
use App\Services\Rental\RentalSettlementService;
use App\Services\Wallet\WalletService;
use App\Support\Rental\SettlementSplit;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * CONFIRMED: a promissory note retained by GamePek is not the end of the story.
 *
 * A GamePek-owned device has no owner to hand the note to, so an unpaid damage
 * leaves the note with GamePek. The customer may still pay the assessed amount
 * afterwards -- and when they do, the physical note goes back to them.
 *
 * The other direction is unchanged: once the note has been handed to a
 * third-party owner, the debt is theirs to pursue and GamePek takes no money
 * for it.
 */
class RentalRetainedNoteTest extends TestCase
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

    public function test_a_gamepek_device_with_unpaid_damage_keeps_the_note_and_stays_payable(): void
    {
        [$application, $inspection] = $this->inspectedReturn('09131000001', null, 'RET-A');
        $this->damage->record($inspection, $this->admin, 4_000_000);

        $this->notes->retainByGamePek($application, $this->admin);

        $this->assertSame(GuaranteeNoteStatus::RetainedByGamePek, $this->notes->statusFor($application->id));
        $this->assertFalse($this->notes->statusFor($application->id)->isTerminal(),
            'GamePek still physically holds the note');
        $this->assertTrue($this->notes->statusFor($application->id)->isResolved(),
            'retention is a recorded outcome, so closure is not blocked on it');
        $this->assertSame(RentalDamageAssessmentService::UNPAID, $this->damage->statusFor($application->id)['status']);

        // A valid retained note is not a contradiction.
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_the_customer_may_pay_after_the_note_was_retained_and_gets_it_back(): void
    {
        [$application, $inspection] = $this->inspectedReturn('09131000002', null, 'RET-B');
        $this->damage->record($inspection, $this->admin, 4_000_000);
        $this->notes->retainByGamePek($application, $this->admin);

        $payment = $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-RETAINED-1');

        $this->assertSame(4_000_000, $payment->amount);
        $this->assertSame(RentalDamageAssessmentService::PAID, $this->damage->statusFor($application->id)['status']);

        // Exactly one credit, to the GamePek wallet, for exactly the amount.
        $wallet = app(WalletService::class)->gamePekWallet();
        $entries = WalletTransaction::where('wallet_id', $wallet->id)->get();

        $this->assertCount(1, $entries);
        $this->assertSame(4_000_000, $entries->first()->amount);
        $this->assertSame($entries->first()->id, $payment->wallet_transaction_id);
        $this->assertSame(4_000_000, (int) Wallet::whereKey($wallet->id)->value('balance'));

        // And now the physical note goes back to the customer.
        $event = $this->notes->returnToCustomer($application->refresh(), $this->admin);

        $this->assertSame(GuaranteeNoteEvent::BASIS_DAMAGE_PAID, $event->basis);
        $this->assertSame(GuaranteeNoteStatus::ReturnedToCustomer, $this->notes->statusFor($application->id));
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_a_repeated_payment_after_retention_credits_the_wallet_once(): void
    {
        [$application, $inspection] = $this->inspectedReturn('09131000003', null, 'RET-C');
        $this->damage->record($inspection, $this->admin, 2_500_000);
        $this->notes->retainByGamePek($application, $this->admin);

        $first = $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-RETAINED-2');
        $second = $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-RETAINED-3');

        $this->assertSame($first->id, $second->id, 'a repeat returns the first payment unchanged');
        $this->assertSame(1, RentalDamagePayment::count());
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(2_500_000, (int) Wallet::whereKey(app(WalletService::class)->gamePekWallet()->id)->value('balance'));
    }

    public function test_a_retained_note_is_never_also_handed_to_an_owner(): void
    {
        [$application, $inspection] = $this->inspectedReturn('09131000004', null, 'RET-D');
        $this->damage->record($inspection, $this->admin, 1_000_000);
        $this->notes->retainByGamePek($application, $this->admin);

        try {
            $this->notes->transferToOwner($application->refresh(), $this->admin);
            $this->fail('A note kept by GamePek must not also be handed to an owner.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(GuaranteeNoteStatus::RetainedByGamePek, $this->notes->statusFor($application->id));
        $this->assertSame(2, GuaranteeNoteEvent::where('rental_application_id', $application->id)->count());
    }

    public function test_a_note_handed_to_an_owner_still_refuses_a_customer_payment(): void
    {
        [$application, $inspection, $reservation] = $this->inspectedReturn('09131000005', '09139000005', 'RET-E');
        $this->damage->record($inspection, $this->admin, 3_000_000);

        $this->notes->transferToOwner($application->refresh(), $this->admin);

        $this->expectException(\RuntimeException::class);

        try {
            $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-AFTER-TRANSFER');
        } finally {
            $this->assertSame(0, RentalDamagePayment::count());
            $this->assertSame(0, WalletTransaction::count());
            $this->assertNotNull($reservation);
        }
    }

    public function test_the_reconciler_reports_damage_money_taken_after_a_note_went_to_the_owner(): void
    {
        [$application, $inspection] = $this->inspectedReturn('09131000006', '09139000006', 'RET-F');
        $assessment = $this->damage->record($inspection, $this->admin, 3_000_000);
        $this->notes->transferToOwner($application->refresh(), $this->admin);

        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());

        // A payment row written around the service, as an import or a hand-run
        // statement could leave behind.
        DB::table('rental_damage_payments')->insert([
            'rental_damage_assessment_id' => $assessment->id,
            'rental_application_id' => $application->id,
            'amount' => 3_000_000,
            'payment_reference' => 'MANUAL',
            'recorded_by_user_id' => $this->admin->id,
            'paid_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertContains(
            OperationCustodyReconciler::DAMAGE_PAYMENT_AFTER_NOTE_TRANSFER,
            app(OperationCustodyReconciler::class)->findings()->pluck('code')->all(),
        );
    }

    public function test_a_cancelled_rental_refuses_a_damage_payment(): void
    {
        [$application, $inspection] = $this->inspectedReturn('09131000007', null, 'RET-G');
        $this->damage->record($inspection, $this->admin, 1_500_000);

        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'cancelled']);

        try {
            $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-CANCELLED');
            $this->fail('A cancelled rental must not take damage money.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_damage_free_return_has_nothing_to_pay(): void
    {
        [$application, $inspection] = $this->inspectedReturn('09131000008', null, 'RET-H');
        $this->damage->record($inspection, $this->admin, 0);

        $this->assertSame(RentalDamageAssessmentService::NO_DAMAGE, $this->damage->statusFor($application->id)['status']);

        try {
            $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-ZERO');
            $this->fail('There is nothing to pay for a damage-free return.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_paid_damage_never_changes_the_owners_share(): void
    {
        [$application, $inspection, $reservation] = $this->inspectedReturn('09131000009', '09139000009', 'RET-I');
        $this->damage->record($inspection, $this->admin, 5_000_000);

        $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-OWNER-DEVICE');

        $settlement = app(RentalSettlementService::class)->calculate($application->refresh(), $this->admin);
        $expected = SettlementSplit::of((int) $reservation->rental_total);

        $this->assertSame((int) $reservation->rental_total, $settlement->gross_amount,
            'damage money is never netted against the rental price');
        $this->assertSame($expected->ownerShare, $settlement->owner_share);
        $this->assertSame($expected->gamepekShare, $settlement->gamepek_share);

        // The damage went to GamePek in full, separately from the split.
        $gamePek = app(WalletService::class)->gamePekWallet();
        $this->assertSame(5_000_000, (int) Wallet::whereKey($gamePek->id)->value('balance'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * Approved -> note received -> delivered -> returned -> inspected.
     *
     * @return array{0: RentalApplication, 1: RentalInspection, 2: RentalReservation}
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

        return [$application->refresh(), $inspection, $reservation->refresh()];
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
