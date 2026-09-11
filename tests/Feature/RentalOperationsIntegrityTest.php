<?php

namespace Tests\Feature;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalOperationService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Cross-cutting integrity of the operations domain: stale owner returns,
 * lifecycle-vs-operation reconciliation, staff-only assignment, read-only
 * page rendering, and the wallet staying untouched by physical operations.
 */
class RentalOperationsIntegrityTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $admin;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

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
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function ownerDevice(Product $product, string $mobile, string $serial): Device
    {
        $devices = app(DeviceRegistrationService::class);
        $user = User::create(['full_name' => 'مالک '.$serial, 'mobile' => $mobile, 'status' => 'active']);

        return $devices->approve(
            $devices->registerForOwner($devices->ensureOwnerProfile($user), $product, $serial),
            $this->admin,
        )->refresh();
    }

    private function operation(RentalReservation $reservation, RentalOperationType $type): RentalOperation
    {
        return RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', $type->value)
            ->firstOrFail();
    }

    private function deliverAndReturn(RentalReservation $reservation, bool $return = true): void
    {
        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);

        if (! $return) {
            return;
        }

        $back = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($back->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($back->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($back->refresh(), $this->admin);
    }

    /** An owner device collected by GamePek, delivered and returned. */
    private function returnedRental(string $mobile, string $ownerMobile, string $serial): array
    {
        $customer = $this->customer($mobile);
        $application = $this->signedApplication($customer);
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $reservation = $application->reservation()->firstOrFail();
        $device = $this->ownerDevice($application->product, $ownerMobile, $serial);

        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
        $this->operations->attachDevice($pickup, $device, $this->admin);
        $this->operations->start($pickup->refresh(), $this->admin);
        $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
        $this->custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);

        $this->deliverAndReturn($reservation);

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh()];
    }

    /** A paid, later, non-overlapping rental of the same console. */
    private function laterRental(
        Device $device,
        RentalReservation $after,
        string $mobile,
        int $offset = 10,
        bool $approved = false,
    ): RentalReservation {
        $application = $approved
            // A second guarantee needs its own cheque id (unique per guarantee).
            ? $this->signedApplication($this->customer($mobile), '13579', $this->uniqueNationalCode(), '9876543210987654')
            : $this->paidApplication($this->customer($mobile), $this->uniqueNationalCode());

        if ($approved) {
            app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        }

        $reservation = $application->reservation()->firstOrFail();

        DB::table('rental_reservations')->where('id', $reservation->id)->update([
            'product_id' => $device->product_id,
            'start_date' => $after->end_date->copy()->addDays($offset)->toDateString(),
            'end_date' => $after->end_date->copy()->addDays($offset + 2)->toDateString(),
        ]);

        return $reservation->refresh();
    }

    // ── 1. Stale owner returns never block a console forever ─────────────

    /**
     * Regression for a bug the previous batch introduced: the allocation guard
     * treated EVERY open owner return as "device leaving", including one that
     * could never run because later movements had superseded it -- so a single
     * stale task kept the console off the market permanently.
     */
    public function test_a_superseded_owner_return_does_not_block_allocating_the_device_again(): void
    {
        [, , $first, $device] = $this->returnedRental('09170004001', '09170004002', 'INT-001');

        // Rental B -- approved -- is promised the console GamePek holds.
        $second = $this->laterRental($device, $first, '09170004003', 10, true);
        $this->operations->attachDevice($this->operation($second, RentalOperationType::OwnerDevicePickup), $device, $this->admin);

        // A legacy owner-return task for rental A (written before today's
        // opener refused this case) sits open.
        DB::table('rental_operations')->insert([
            'operation_number' => 'OPS-LEGACY-0001',
            'rental_reservation_id' => $first->id,
            'rental_application_id' => $first->rental_application_id,
            'device_id' => $device->id,
            'owner_id' => $device->owner_id,
            'type' => RentalOperationType::OwnerReturn->value,
            'state' => RentalOperationState::Scheduled->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rental B runs its whole course; A's owner return is now superseded.
        $this->deliverAndReturn($second);

        // Rental C can still have the console.
        $third = $this->laterRental($device, $second, '09170004004', 20);
        $pickup = $this->operations->attachDevice($this->operation($third, RentalOperationType::OwnerDevicePickup), $device, $this->admin);

        $this->assertSame(RentalOperationState::NotRequired, $pickup->state);
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());
    }

    public function test_a_failed_but_retryable_owner_return_still_holds_the_device(): void
    {
        [, , $first, $device] = $this->returnedRental('09170004005', '09170004006', 'INT-002');

        $ownerReturn = $this->operations->openOwnerReturnForReservation($first, $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);
        $this->operations->fail($ownerReturn->refresh(), $this->admin, 'مالک در دسترس نبود');

        $second = $this->laterRental($device, $first, '09170004007');

        $this->expectException(\RuntimeException::class);
        $this->operations->attachDevice($this->operation($second, RentalOperationType::OwnerDevicePickup), $device, $this->admin);
    }

    // ── 2. Lifecycle and operations must agree (reconciler, read-only) ───

    public function test_the_reconciler_reports_a_rental_active_without_a_delivery(): void
    {
        $application = $this->signedApplication($this->customer('09170004008'));
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);

        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'active']);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::ACTIVE_WITHOUT_DELIVERY, $codes);
        $this->assertNotContains(OperationCustodyReconciler::RETURNED_WITHOUT_RETURN, $codes);
        // Read-only: nothing was repaired.
        $this->assertSame('active', DB::table('rental_applications')->where('id', $application->id)->value('state'));
    }

    public function test_the_reconciler_reports_a_lifecycle_that_fell_behind_its_operations(): void
    {
        [, $application] = $this->returnedRental('09170004009', '09170004010', 'INT-003');

        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'approved']);

        $findings = app(OperationCustodyReconciler::class)->findings()
            ->where('code', OperationCustodyReconciler::LIFECYCLE_BEHIND_OPERATION);

        // Both the delivery and the return completed without the rental moving.
        $this->assertCount(2, $findings);
    }

    public function test_the_reconciler_reports_a_rental_returned_without_a_return(): void
    {
        [, $application, $reservation] = $this->returnedRental('09170004011', '09170004012', 'INT-004');

        DB::table('rental_operations')
            ->where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::CustomerReturn->value)
            ->update(['state' => RentalOperationState::InProgress->value]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::RETURNED_WITHOUT_RETURN, $codes);
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }

    public function test_a_consistent_lifecycle_produces_no_lifecycle_findings(): void
    {
        $this->returnedRental('09170004013', '09170004014', 'INT-005');

        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    // ── 3. Only staff carry out GamePek's physical work ──────────────────

    public function test_a_customer_cannot_be_assigned_as_the_responsible_staff_member(): void
    {
        $application = $this->signedApplication($this->customer('09170004015'));
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $reservation = $application->reservation()->firstOrFail();
        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
        $this->operations->attachDevice($pickup, $this->ownerDevice($application->product, '09170004016', 'INT-006'), $this->admin);

        $outsider = $this->customer('09170004017');

        try {
            $this->operations->schedule($pickup->refresh(), $this->admin, null, $outsider->id);
            $this->fail('A customer must not be assignable to a GamePek operation.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->actingAs($this->admin)
            ->post(route('admin.operations.schedule', $pickup), ['assigned_to_user_id' => $outsider->id])
            ->assertSessionHas('error');

        $this->assertNull($pickup->refresh()->assigned_to_user_id);

        // Staff are still assignable.
        $this->operations->schedule($pickup->refresh(), $this->admin, null, $this->admin->id);
        $this->assertSame($this->admin->id, $pickup->refresh()->assigned_to_user_id);
    }

    // ── 4. Admin visibility ──────────────────────────────────────────────

    public function test_the_operations_queue_can_be_filtered_by_type(): void
    {
        [, , $reservation] = $this->returnedRental('09170004018', '09170004019', 'INT-007');

        $delivery = $this->operation($reservation, RentalOperationType::CustomerDelivery);
        $return = $this->operation($reservation, RentalOperationType::CustomerReturn);

        $this->actingAs($this->admin)
            ->get(route('admin.operations.index', ['type' => 'customer_delivery']))
            ->assertOk()
            ->assertSee($delivery->operation_number)
            ->assertDontSee($return->operation_number);

        // An unknown type filters nothing instead of failing.
        $this->actingAs($this->admin)
            ->get(route('admin.operations.index', ['type' => 'not_a_type']))
            ->assertOk()
            ->assertSee($return->operation_number);
    }

    // ── 5. Customer confirmation of the return, after Returned ───────────

    public function test_the_customer_can_confirm_the_return_record_once_the_rental_is_returned(): void
    {
        [$customer, $application, $reservation] = $this->returnedRental('09170004020', '09170004021', 'INT-008');
        $return = $this->operation($reservation, RentalOperationType::CustomerReturn);

        $this->actingAs($customer)
            ->post(route('rental.applications.handover.acknowledge', $application), ['operation' => $return->id])
            ->assertRedirect();

        $this->assertSame(CustodyTransferState::Acknowledged, $return->custodyTransfer()->firstOrFail()->state);
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }

    // ── 6. Rendering never mutates; operations never touch money ─────────

    public function test_rendering_every_rental_page_changes_no_lifecycle_state(): void
    {
        [$customer, $application, $reservation, $device] = $this->returnedRental('09170004022', '09170004023', 'INT-009');
        $return = $this->operation($reservation, RentalOperationType::CustomerReturn);
        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);

        $snapshot = fn () => [
            RentalApplicationTransition::count(),
            RentalApplication::findOrFail($application->id)->state,
            RentalOperation::orderBy('id')->pluck('state', 'id')->map->value->all(),
            DB::table('device_custody_transfers')->orderBy('id')->pluck('state', 'id')->all(),
            DB::table('rental_reservations')->where('id', $reservation->id)->value('state'),
        ];

        $before = $snapshot();

        $this->actingAs($customer)->get(route('rental.dashboard'))->assertOk();
        $this->actingAs($customer)->get(route('rental.applications.index'))->assertOk();
        $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();

        $this->actingAs($this->admin)->get(route('admin.rental-applications.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rental-applications.show', $application))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.operations.show', $return))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.operations.reconciliation'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.devices.custody', $device))->assertOk();

        $this->actingAs($device->owner->user)->get(route('owner.operations.index'))->assertOk();
        $this->actingAs($device->owner->user)->get(route('owner.operations.show', $pickup))->assertOk();

        $this->assertSame($before, $snapshot());
    }

    public function test_the_physical_lifecycle_never_touches_a_wallet(): void
    {
        [, $application, $reservation] = $this->returnedRental('09170004024', '09170004025', 'INT-010');

        $ownerReturn = $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);
        $this->custody->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        $this->custody->recordReturnToOwner($ownerReturn->refresh(), $this->admin);

        // No settlement, deposit, refund or damage rule exists, so no
        // operational step may create a wallet or move a balance.
        $this->assertSame(0, Wallet::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }
}
