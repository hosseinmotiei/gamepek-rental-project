<?php

namespace Tests\Feature;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\DeviceOwnership;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Enums\ReservationState;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\Order;
use App\Models\Owner;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\PaymentService;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalReservationService;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Operations and custody: the pickup task, and possession as a fact separate
 * from ownership.
 *
 * The invariant under test throughout is that CUSTODY IS NOT OWNERSHIP. An
 * owner who hands GamePek a console for a rental still owns it afterwards, and
 * no amount of operational activity may change that.
 *
 * The second theme is that nothing here guesses. Device allocation is an
 * undecided policy, so a task with no device says so instead of picking one.
 */
class RentalOperationsCustodyTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

    private DeviceRegistrationService $devices;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);

        $this->operations = app(RentalOperationService::class);
        $this->custody = app(DeviceCustodyService::class);
        $this->devices = app(DeviceRegistrationService::class);

        $this->admin = User::where('mobile', '09100000001')->firstOrFail();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function makeOwnerWithApprovedDevice(Product $product, string $mobile, string $serial): Device
    {
        $user = User::create(['full_name' => 'مالک دستگاه', 'mobile' => $mobile, 'status' => 'active']);
        $owner = $this->devices->ensureOwnerProfile($user);

        $device = $this->devices->registerForOwner($owner, $product, $serial);

        return $this->devices->approve($device, $this->admin)->refresh();
    }

    private function makeGamePekDevice(Product $product, string $serial): Device
    {
        $device = $this->devices->registerForGamePek($product, $serial, [], $this->admin);

        return $this->devices->approve($device, $this->admin)->refresh();
    }

    /** A paid application, its reservation, and the pickup task that came with it. */
    private function paidWithOperation(string $mobile): array
    {
        $customer = $this->customer($mobile);
        $application = $this->paidApplication($customer, $this->uniqueNationalCode());
        $reservation = $application->reservation()->firstOrFail();
        $operation = RentalOperation::where('rental_reservation_id', $reservation->id)->firstOrFail();

        return [$customer, $application, $reservation, $operation];
    }

    /** Task carried all the way to in_progress against a real owner device. */
    private function pickupInProgress(string $mobile, string $ownerMobile, string $serial): array
    {
        [$customer, $application, $reservation, $operation] = $this->paidWithOperation($mobile);

        $device = $this->makeOwnerWithApprovedDevice($application->product, $ownerMobile, $serial);

        $this->operations->attachDevice($operation, $device, $this->admin);
        $this->operations->start($operation->refresh(), $this->admin);
        $this->custody->requestFromOwner($operation->refresh(), $this->admin);

        return [$customer, $application, $operation->refresh(), $device->refresh()];
    }

    // ── 1-6. Operation creation ──────────────────────────────────────────

    public function test_a_paid_reservation_creates_exactly_one_pickup_operation(): void
    {
        [, , $reservation, $operation] = $this->paidWithOperation('09120001001');

        $this->assertSame(1, RentalOperation::count());
        $this->assertSame(RentalOperationType::OwnerDevicePickup, $operation->type);
        $this->assertSame($reservation->id, $operation->rental_reservation_id);

        // Born without a device, because allocation is undecided. It says so
        // rather than picking one.
        $this->assertNull($operation->device_id);
        $this->assertSame(RentalOperationState::AwaitingDeviceAllocation, $operation->state);
    }

    public function test_an_unpaid_application_creates_no_operation(): void
    {
        $application = $this->reservedApplication($this->customer('09120001002'), $this->uniqueNationalCode());

        $this->assertNull($application->reservation()->first());
        $this->assertSame(0, RentalOperation::count());
    }

    public function test_a_failed_payment_creates_no_operation(): void
    {
        $customer = $this->customer('09120001003');
        $application = $this->reservedApplication($customer, $this->uniqueNationalCode());

        $order = $this->attachUnpaidOrder($application);

        $service = app(PaymentService::class);
        $init = $service->initiatePayment($order);

        // No server-side success was recorded, so verification fails.
        $result = $service->handleCallback(['Authority' => $init['authority'], 'Status' => 'OK'], 'mock');

        $this->assertFalse($result['success']);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, RentalReservation::count());
        $this->assertSame(0, RentalOperation::count());
    }

    public function test_a_rejected_application_creates_no_operation(): void
    {
        $application = $this->reservedApplication($this->customer('09120001004'), $this->uniqueNationalCode());

        $application->forceFill([
            'state' => RentalApplicationState::Rejected,
            'rejected_at' => now(),
        ])->save();

        $this->assertSame(0, RentalOperation::count());
    }

    public function test_a_replayed_payment_callback_creates_no_duplicate_operation(): void
    {
        $customer = $this->customer('09120001005');
        $application = $this->reservedApplication($customer, $this->uniqueNationalCode());
        $order = $this->attachUnpaidOrder($application);

        $service = app(PaymentService::class);
        $init = $service->initiatePayment($order);
        MockGateway::recordOutcome($init['authority'], 'paid');

        $first = $service->handleCallback(['Authority' => $init['authority']], 'mock');
        $this->assertTrue($first['success']);

        // The customer refreshes the success page; the gateway retries.
        $service->handleCallback(['Authority' => $init['authority']], 'mock');
        $service->handleCallback(['Authority' => $init['authority']], 'mock');

        $this->assertSame(1, RentalReservation::count());
        $this->assertSame(1, RentalOperation::count());
    }

    public function test_repeated_materialisation_creates_no_duplicate_operation(): void
    {
        [, $application] = $this->paidWithOperation('09120001006');

        $reservations = app(RentalReservationService::class);
        $reservations->materialiseAfterPayment($application->refresh());
        $reservations->materialiseAfterPayment($application->refresh());

        $this->assertSame(1, RentalReservation::count());
        $this->assertSame(1, RentalOperation::count());
    }

    // ── 7-10. Device dependency ──────────────────────────────────────────

    public function test_a_pickup_without_a_device_cannot_be_started_or_completed(): void
    {
        [, , , $operation] = $this->paidWithOperation('09120001007');

        $this->expectException(\RuntimeException::class);
        $this->operations->start($operation, $this->admin);
    }

    public function test_no_device_is_selected_automatically(): void
    {
        [, $application, $reservation, $operation] = $this->paidWithOperation('09120001008');

        // Two approved devices are sitting there, ready, for exactly this
        // product. Neither is chosen: the allocation rule is undecided and
        // guessing it would quietly pick winners among owners.
        $this->makeOwnerWithApprovedDevice($application->product, '09130001001', 'AAA-111');
        $this->makeGamePekDevice($application->product, 'BBB-222');

        $this->assertNull($operation->fresh()->device_id);
        $this->assertNull($reservation->fresh()->device_id);
        $this->assertSame(RentalOperationState::AwaitingDeviceAllocation, $operation->fresh()->state);
    }

    public function test_a_gamepek_owned_device_creates_no_owner_pickup_and_no_custody_transfer(): void
    {
        [, $application, , $operation] = $this->paidWithOperation('09120001009');

        $device = $this->makeGamePekDevice($application->product, 'GP-0001');

        $this->operations->attachDevice($operation, $device, $this->admin);
        $operation->refresh();

        // Not "completed": no handover happened, because none was needed.
        $this->assertSame(RentalOperationState::NotRequired, $operation->state);
        $this->assertSame(0, DeviceCustodyTransfer::count());

        // No fake owner account was manufactured to hold GamePek's own stock.
        $this->assertNull($device->fresh()->owner_id);
        $this->assertSame(DeviceOwnership::GamePek, $device->fresh()->ownership);

        // Custody is GamePek without any transfer row saying so.
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());

        // And a handover cannot be forced onto it.
        $this->expectException(\RuntimeException::class);
        $this->custody->requestFromOwner($operation, $this->admin);
    }

    public function test_a_device_of_another_product_cannot_be_attached(): void
    {
        [, , , $operation] = $this->paidWithOperation('09120001010');

        $foreign = $this->makeOwnerWithApprovedDevice($this->makeRentableProduct(), '09130001002', 'XX-999');

        $this->expectException(\RuntimeException::class);
        $this->operations->attachDevice($operation, $foreign, $this->admin);
    }

    public function test_an_unapproved_device_cannot_be_attached(): void
    {
        [, $application, , $operation] = $this->paidWithOperation('09120001011');

        $user = User::create(['full_name' => 'مالک', 'mobile' => '09130001003', 'status' => 'active']);
        $owner = $this->devices->ensureOwnerProfile($user);
        $pending = $this->devices->registerForOwner($owner, $application->product, 'PEND-1');

        $this->expectException(\RuntimeException::class);
        $this->operations->attachDevice($operation, $pending, $this->admin);
    }

    // ── 11-14. State transitions ─────────────────────────────────────────

    public function test_the_pickup_ladder_runs_end_to_end(): void
    {
        [, , $operation, $device] = $this->pickupInProgress('09120001012', '09130001004', 'LAD-001');

        $this->assertSame(RentalOperationState::InProgress, $operation->state);

        $this->custody->recordHandoverToGamePek($operation, $this->admin, 'تحویل در محل');

        $operation->refresh();
        $this->assertSame(RentalOperationState::Completed, $operation->state);
        $this->assertNotNull($operation->completed_at);
        $this->assertSame($this->admin->id, $operation->completed_by_user_id);
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());
    }

    public function test_an_invalid_transition_is_refused(): void
    {
        [, $application, , $operation] = $this->paidWithOperation('09120001013');

        $device = $this->makeOwnerWithApprovedDevice($application->product, '09130001005', 'INV-001');
        $this->operations->attachDevice($operation, $device, $this->admin);

        // scheduled -> completed is not a rung on the ladder; possession has
        // not been recorded, so there is nothing to complete.
        $this->expectException(\RuntimeException::class);
        $this->custody->recordHandoverToGamePek($operation->refresh(), $this->admin);
    }

    public function test_a_completed_operation_cannot_be_reopened(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001014', '09130001006', 'CMP-001');

        $this->custody->recordHandoverToGamePek($operation, $this->admin);
        $operation->refresh();

        $this->assertSame(RentalOperationState::Completed, $operation->state);

        try {
            $this->operations->start($operation, $this->admin);
            $this->fail('A completed pickup was reopened.');
        } catch (\RuntimeException $e) {
            $this->assertSame(RentalOperationState::Completed, $operation->fresh()->state);
        }

        try {
            $this->operations->fail($operation, $this->admin, 'دلیل');
            $this->fail('A completed pickup was marked failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame(RentalOperationState::Completed, $operation->fresh()->state);
        }
    }

    public function test_a_failed_operation_records_its_reason_and_changes_nothing_else(): void
    {
        [, $application, $operation, $device] = $this->pickupInProgress('09120001015', '09130001007', 'FAIL-01');

        $this->operations->fail($operation, $this->admin, 'مالک در محل حاضر نشد');
        $operation->refresh();

        $this->assertSame(RentalOperationState::Failed, $operation->state);
        $this->assertSame('مالک در محل حاضر نشد', $operation->failure_reason);
        $this->assertNotNull($operation->failed_at);

        // POLICY GATE: nothing follows from a failure. The reservation stands,
        // the owner is untouched, no refund exists.
        $reservation = $operation->reservation;
        $this->assertSame(ReservationState::Paid, $reservation->state);
        $this->assertSame($device->owner_id, $device->fresh()->owner_id);
        $this->assertSame('paid', $application->fresh()->order->payment_status);

        // A failed pickup may be retried; the device and reservation are
        // unchanged, so that is unambiguous.
        $this->operations->start($operation, $this->admin);
        $this->assertSame(RentalOperationState::InProgress, $operation->fresh()->state);
    }

    // ── 15-20. Custody ───────────────────────────────────────────────────

    public function test_an_owner_to_gamepek_transfer_succeeds_and_leaves_ownership_untouched(): void
    {
        [, , $operation, $device] = $this->pickupInProgress('09120001016', '09130001008', 'CUS-001');

        $ownerIdBefore = $device->owner_id;
        $ownershipBefore = $device->ownership;

        $transfer = $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $this->assertSame(CustodyTransferState::Transferred, $transfer->state);
        $this->assertSame(CustodyActor::Owner, $transfer->from_actor_type);
        $this->assertSame(CustodyActor::GamePek, $transfer->to_actor_type);
        $this->assertSame($ownerIdBefore, $transfer->from_owner_id);
        $this->assertNotNull($transfer->transferred_at);

        // THE invariant: possession moved, ownership did not.
        $fresh = $device->fresh();
        $this->assertSame($ownerIdBefore, $fresh->owner_id);
        $this->assertSame($ownershipBefore, $fresh->ownership);
        $this->assertSame(DeviceOwnership::Owner, $fresh->ownership);
        $this->assertSame(CustodyActor::GamePek, $fresh->currentCustody());
    }

    public function test_a_duplicate_transfer_is_rejected(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001017', '09130001009', 'DUP-001');

        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        try {
            $this->custody->recordHandoverToGamePek($operation->refresh(), $this->admin);
            $this->fail('A second handover was recorded.');
        } catch (\RuntimeException $e) {
            // Persian, and no stack trace reaches the operator.
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $e->getMessage());
        }

        $this->assertSame(1, DeviceCustodyTransfer::count());
    }

    public function test_a_repeated_custody_request_produces_one_record(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001018', '09130001010', 'REQ-001');

        $this->custody->requestFromOwner($operation, $this->admin);
        $this->custody->requestFromOwner($operation, $this->admin);

        $this->assertSame(1, DeviceCustodyTransfer::count());
    }

    public function test_an_operation_whose_reservation_names_another_device_is_refused(): void
    {
        [, $application, , $operation] = $this->paidWithOperation('09120001019');

        $device = $this->makeOwnerWithApprovedDevice($application->product, '09130001011', 'MIS-001');
        $other = $this->makeOwnerWithApprovedDevice($application->product, '09130001012', 'MIS-002');

        $this->operations->attachDevice($operation, $device, $this->admin);
        $this->operations->start($operation->refresh(), $this->admin);

        // Someone points the reservation at a different console behind the
        // operation's back. The handover must not accept the mismatch.
        $operation->reservation()->update(['device_id' => $other->id]);

        $this->expectException(\RuntimeException::class);
        $this->custody->requestFromOwner($operation->refresh(), $this->admin);
    }

    public function test_an_owner_cannot_see_another_owners_operation(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001020', '09130001013', 'ISO-001');

        $intruderUser = User::create(['full_name' => 'مالک دیگر', 'mobile' => '09130001014', 'status' => 'active']);
        $this->devices->ensureOwnerProfile($intruderUser);

        $this->actingAs($intruderUser)
            ->get(route('owner.operations.show', $operation))
            ->assertForbidden();

        $this->actingAs($intruderUser)
            ->post(route('owner.operations.acknowledge', $operation))
            ->assertForbidden();
    }

    public function test_concurrent_handovers_produce_exactly_one_transfer(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001021', '09130001015', 'CON-001');

        $successes = 0;
        $failures = 0;

        // Serialised in-process rather than truly parallel, but it exercises
        // the same guard: the second caller finds the state already moved.
        // unique(rental_operation_id) is the database-level backstop.
        foreach (range(1, 5) as $ignored) {
            try {
                $this->custody->recordHandoverToGamePek($operation->refresh(), $this->admin);
                $successes++;
            } catch (\RuntimeException $e) {
                $failures++;
            }
        }

        $this->assertSame(1, $successes);
        $this->assertSame(4, $failures);
        $this->assertSame(1, DeviceCustodyTransfer::count());
        $this->assertSame(1, DeviceCustodyTransfer::possessionMoved()->count());
    }

    public function test_the_owner_can_acknowledge_the_recorded_handover(): void
    {
        [, , $operation, $device] = $this->pickupInProgress('09120001022', '09130001016', 'ACK-001');

        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $ownerUser = $device->owner->user;

        $this->actingAs($ownerUser)
            ->post(route('owner.operations.acknowledge', $operation))
            ->assertRedirect();

        $transfer = DeviceCustodyTransfer::firstOrFail();
        $this->assertSame(CustodyTransferState::Acknowledged, $transfer->state);
        $this->assertNotNull($transfer->acknowledged_at);

        // Acknowledging changes nothing about ownership either.
        $this->assertSame(DeviceOwnership::Owner, $device->fresh()->ownership);
        $this->assertNotNull($device->fresh()->owner_id);
    }

    // ── 21-25. Audit ─────────────────────────────────────────────────────

    public function test_the_operation_and_custody_lifecycle_is_audited(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001023', '09130001017', 'AUD-001');

        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        foreach ([
            'operation.created',
            'operation.awaiting_device_allocation',
            'operation.device_attached',
            'operation.started',
            'operation.completed',
            'custody.requested',
            'custody.transferred',
        ] as $action) {
            $this->assertTrue(
                AuditEvent::where('action', $action)->exists(),
                "Missing audit event: {$action}",
            );
        }
    }

    public function test_a_failed_operation_is_audited_as_a_failure(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001024', '09130001018', 'AUD-002');

        $this->operations->fail($operation, $this->admin, 'دستگاه در دسترس نبود');

        $event = AuditEvent::where('action', 'operation.failed')->firstOrFail();

        $this->assertSame('failure', $event->result);
        $this->assertSame('دستگاه در دسترس نبود', (array) $event->context ? ((array) $event->context)['reason'] : null);
    }

    public function test_no_raw_serial_appears_in_operation_or_custody_audit_context(): void
    {
        $serial = 'ZQ-77-5544';
        [, , $operation, $device] = $this->pickupInProgress('09120001025', '09130001019', $serial);

        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $normalized = Device::normalizeSerial($serial);

        foreach (AuditEvent::all() as $event) {
            $blob = json_encode($event->context, JSON_UNESCAPED_UNICODE) ?: '';

            $this->assertStringNotContainsString($serial, $blob);
            $this->assertStringNotContainsString($normalized, $blob);
        }

        // The mask IS present, so the trail is still usable.
        $this->assertTrue(
            AuditEvent::where('action', 'custody.transferred')->get()
                ->contains(fn ($e) => ((array) $e->context)['device_serial_mask'] === $device->maskedSerial()),
        );
    }

    // ── 26-29. Admin screens ─────────────────────────────────────────────

    public function test_admin_can_list_and_inspect_operations_and_custody(): void
    {
        [, , $operation, $device] = $this->pickupInProgress('09120001026', '09130001020', 'ADM-001');

        $this->actingAs($this->admin)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.operations.show', $operation))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.devices.custody', $device))->assertOk();
    }

    public function test_the_operations_list_renders_with_no_operations(): void
    {
        $this->actingAs($this->admin)->get(route('admin.operations.index'))->assertOk();
    }

    public function test_a_staff_member_without_the_permission_cannot_change_operation_state(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001027', '09130001021', 'PRM-001');

        // `support` reaches the admin panel but holds neither view_operations
        // nor manage_operations.
        $support = User::create(['full_name' => 'پشتیبانی', 'mobile' => '09140001001', 'status' => 'active']);
        $support->syncRoles(['support']);

        $this->actingAs($support)->get(route('admin.operations.index'))->assertForbidden();
        $this->actingAs($support)->post(route('admin.operations.custody', $operation))->assertForbidden();
        $this->actingAs($support)->post(route('admin.operations.fail', $operation), ['reason' => 'x'])->assertForbidden();

        $this->assertSame(RentalOperationState::InProgress, $operation->fresh()->state);
        $this->assertSame(0, DeviceCustodyTransfer::possessionMoved()->count());
    }

    public function test_an_owner_cannot_drive_the_operation_through_admin_routes(): void
    {
        [, , $operation, $device] = $this->pickupInProgress('09120001028', '09130001022', 'PRM-002');

        $ownerUser = $device->owner->user;

        // EnsureIsAdmin redirects non-admins rather than 403-ing them.
        $this->actingAs($ownerUser)
            ->post(route('admin.operations.custody', $operation))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(0, DeviceCustodyTransfer::possessionMoved()->count());
    }

    public function test_lifecycle_fields_are_not_mass_assignable(): void
    {
        [, , $operation] = $this->pickupInProgress('09120001029', '09130001023', 'MAS-001');

        // Model::shouldBeStrict() is on outside production, so an attempt to
        // mass-assign a lifecycle field throws rather than being silently
        // dropped. Either way the field is unwritable; this asserts the louder
        // behaviour the app actually runs with.
        try {
            $operation->fill([
                'state' => RentalOperationState::Completed->value,
                'device_id' => 999,
                'owner_id' => 999,
                'completed_at' => now(),
                'completed_by_user_id' => 1,
            ]);
            $this->fail('Lifecycle fields were mass-assignable.');
        } catch (MassAssignmentException $e) {
            // Expected.
        }

        $fresh = $operation->fresh();
        $this->assertSame(RentalOperationState::InProgress, $fresh->state);
        $this->assertNotSame(999, $fresh->device_id);
        $this->assertNull($fresh->completed_at);
    }

    // ── 30-33. Regression ────────────────────────────────────────────────

    public function test_phase_02_payment_gate_and_post_payment_reservation_still_hold(): void
    {
        // No KYC at all: the payment gate must still refuse.
        $stranger = $this->customer('09120001030');
        $application = app(RentalReservationService::class)->openApplication($stranger);

        $this->assertNotNull(
            app(RentalChainOrchestrator::class)->paymentBlockedReason($application),
        );

        // Selection alone reserves nothing and blocks no inventory.
        $selected = $this->reservedApplication($this->customer('09120001031'), $this->uniqueNationalCode());
        $this->assertNull($selected->reservation()->first());
        $this->assertSame(0, RentalReservation::count());
        $this->assertSame(0, RentalOperation::count());
    }

    public function test_availability_is_unchanged_by_the_operations_domain(): void
    {
        [, $application, , $operation] = $this->paidWithOperation('09120001032');

        $availability = app(RentalAvailabilityService::class);
        $start = $application->selected_start_date->toDateString();
        $end = $application->selected_end_date->toDateString();

        $this->assertFalse($availability->isFree($application->product_id, $start, $end));

        // Attaching a device is an operational fact, not an availability one.
        $device = $this->makeOwnerWithApprovedDevice($application->product, '09130001024', 'AVL-001');
        $this->operations->attachDevice($operation, $device, $this->admin);

        $this->assertFalse($availability->isFree($application->product_id, $start, $end));
        $this->assertTrue($availability->isFree(
            $application->product_id,
            now()->addYear()->toDateString(),
            now()->addYear()->addDays(2)->toDateString(),
        ));
    }

    public function test_phase_03_owner_isolation_still_holds(): void
    {
        [, $application] = $this->paidWithOperation('09120001033');

        $device = $this->makeOwnerWithApprovedDevice($application->product, '09130001025', 'P03-001');

        $intruder = User::create(['full_name' => 'مزاحم', 'mobile' => '09130001026', 'status' => 'active']);
        $this->devices->ensureOwnerProfile($intruder);

        $this->actingAs($intruder)->get(route('owner.devices.show', $device))->assertForbidden();
    }

    public function test_no_operational_history_is_manufactured_for_preexisting_reservations(): void
    {
        // A reservation written before this domain existed: no operation row.
        $customer = $this->customer('09120001034');
        $application = $this->reservedApplication($customer, $this->uniqueNationalCode());

        $reservation = RentalReservation::create([
            'rental_application_id' => $application->id,
            'product_id' => $application->product_id,
            'start_date' => $application->selected_start_date,
            'end_date' => $application->selected_end_date,
            'days' => $application->selected_days,
            'state' => ReservationState::Paid,
        ]);

        $this->assertSame(0, RentalOperation::count());

        // And materialising again does not retro-fit one, because the
        // reservation already exists.
        $order = Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $customer->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'subtotal' => 0, 'total' => 0, 'paid_at' => now(),
        ]);
        $application->update(['order_id' => $order->id]);

        app(RentalReservationService::class)->materialiseAfterPayment($application->refresh());

        $this->assertSame(0, RentalOperation::count());
        $this->assertSame(1, RentalReservation::where('id', $reservation->id)->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function attachUnpaidOrder(RentalApplication $application): Order
    {
        $quote = (array) $application->quote;

        $order = Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $application->user_id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'subtotal' => $quote['rental_total'] ?? 0,
            'shipping_cost' => $quote['delivery_fee'] ?? 0,
            'total' => $quote['payable_now'] ?? 0,
        ]);

        $application->update(['order_id' => $order->id]);

        return $order;
    }
}
