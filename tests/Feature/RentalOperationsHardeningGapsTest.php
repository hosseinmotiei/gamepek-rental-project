<?php

namespace Tests\Feature;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferType;
use App\Enums\RentalOperationState;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\Product;
use App\Models\RentalOperation;
use App\Models\User;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\RentalOperationService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Focused gaps found while auditing RentalOperationsCustodyTest and
 * RentalCustodyHardeningTest for Task #16 -- everything else those two files
 * already assert is NOT repeated here.
 */
class RentalOperationsHardeningGapsTest extends TestCase
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

    // ── Fixtures (mirrors the existing custody test files) ────────────────

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function ownerDevice(Product $product, string $mobile, string $serial): Device
    {
        $user = User::create(['full_name' => 'مالک دستگاه', 'mobile' => $mobile, 'status' => 'active']);
        $owner = $this->devices->ensureOwnerProfile($user);

        return $this->devices->approve(
            $this->devices->registerForOwner($owner, $product, $serial),
            $this->admin,
        )->refresh();
    }

    private function paidWithOperation(string $mobile): array
    {
        $application = $this->paidApplication($this->customer($mobile), $this->uniqueNationalCode());
        $reservation = $application->reservation()->firstOrFail();

        return [$application, RentalOperation::where('rental_reservation_id', $reservation->id)->firstOrFail()];
    }

    // ── 1. RentalOperationService::schedule() had NO test coverage at all ──

    public function test_schedule_sets_the_planned_time_and_assignee_once_a_device_is_attached(): void
    {
        [$application, $operation] = $this->paidWithOperation('09140001001');
        $device = $this->ownerDevice($application->product, '09140001002', 'SCH-001');
        $staff = User::create(['full_name' => 'کارشناس', 'mobile' => '09140001003', 'status' => 'active']);

        $this->operations->attachDevice($operation, $device, $this->admin);

        $result = $this->operations->schedule($operation->refresh(), $this->admin, '2026-10-01 10:00:00', $staff->id);

        $this->assertSame('2026-10-01 10:00:00', $result->fresh()->scheduled_at->toDateTimeString());
        $this->assertSame($staff->id, $result->fresh()->assigned_to_user_id);
        // schedule() does not move the state ladder.
        $this->assertSame(RentalOperationState::Scheduled, $result->fresh()->state);
    }

    public function test_schedule_is_refused_when_the_operation_is_not_in_the_scheduled_state(): void
    {
        [, $operation] = $this->paidWithOperation('09140001004');

        // Still awaiting_device_allocation: no device attached yet.
        $this->expectException(\RuntimeException::class);
        $this->operations->schedule($operation, $this->admin, '2026-10-01 10:00:00');
    }

    // ── 2. start() had no test for "already in progress" ──────────────────

    public function test_starting_an_already_in_progress_operation_is_refused_without_side_effects(): void
    {
        [$application, $operation] = $this->paidWithOperation('09140001005');
        $device = $this->ownerDevice($application->product, '09140001006', 'SCH-002');

        $this->operations->attachDevice($operation, $device, $this->admin);
        $this->operations->start($operation->refresh(), $this->admin);

        $beforeState = $operation->fresh()->state;
        $beforeStartedAt = $operation->fresh()->started_at->toDateTimeString();

        try {
            $this->operations->start($operation->fresh(), $this->admin);
            $this->fail('Starting an already in_progress operation must be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($beforeState, $operation->fresh()->state);
        $this->assertSame($beforeStartedAt, $operation->fresh()->started_at->toDateTimeString());
    }

    // ── 3. Acknowledging a transfer before possession has actually moved ──

    public function test_an_owner_cannot_acknowledge_a_transfer_that_has_not_been_handed_over_yet(): void
    {
        [$application, $operation] = $this->paidWithOperation('09140001007');
        $device = $this->ownerDevice($application->product, '09140001008', 'SCH-003');

        $this->operations->attachDevice($operation, $device, $this->admin);
        $this->operations->start($operation->refresh(), $this->admin);
        $transfer = $this->custody->requestFromOwner($operation->refresh(), $this->admin);

        $this->assertSame('requested', $transfer->state->value);

        try {
            $this->custody->acknowledgeByOwner($transfer, $device->owner->user);
            $this->fail('Acknowledging a transfer still in "requested" must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('این تحویل هنوز ثبت نشده است و قابل تأیید نیست.', $e->getMessage());
        }

        $this->assertSame('requested', $transfer->fresh()->state->value);
        $this->assertNull($transfer->fresh()->acknowledged_at);
    }

    public function test_the_owner_acknowledge_route_refuses_a_not_yet_transferred_custody_record(): void
    {
        [$application, $operation] = $this->paidWithOperation('09140001009');
        $device = $this->ownerDevice($application->product, '09140001010', 'SCH-004');

        $this->operations->attachDevice($operation, $device, $this->admin);
        $this->operations->start($operation->refresh(), $this->admin);
        $this->custody->requestFromOwner($operation->refresh(), $this->admin);

        $this->actingAs($device->owner->user)
            ->post(route('owner.operations.acknowledge', $operation->refresh()))
            ->assertRedirect();

        $this->assertSame('requested', DeviceCustodyTransfer::firstOrFail()->state->value);
    }

    // ── 4. A GamePek-owned device's operation carries no owner_id ─────────

    public function test_a_gamepek_owned_devices_operation_has_no_owner_id(): void
    {
        [$application, $operation] = $this->paidWithOperation('09140001011');
        $device = $this->devices->approve(
            $this->devices->registerForGamePek($application->product, 'SCH-005', [], $this->admin),
            $this->admin,
        );

        $result = $this->operations->attachDevice($operation, $device->refresh(), $this->admin);

        $this->assertNull($result->fresh()->owner_id);
        $this->assertSame(RentalOperationState::NotRequired, $result->fresh()->state);
    }

    // ── 5. DeviceCustodyTransfer::actorsMatchType() -- unit-level only ─────
    //
    // OperationCustodyReconciler::ACTOR_PAIR_MISMATCH exists to catch a row
    // whose declared transfer_type and actual actors disagree. Today that
    // combination cannot be PERSISTED at all: CustodyTransferType has exactly
    // one case (OwnerToGamePek), and the `device_custody_actor_pair_ck`
    // database constraint (docs/operations/OPERATIONS_AND_CUSTODY.md §6)
    // refuses any row of that type whose actors are not owner -> gamepek --
    // even via a raw, service-bypassing write. A true reconciler-level
    // integration test would require deliberately violating that constraint,
    // which is out of scope here. What IS tested, without touching the
    // database, is the PHP predicate the reconciler relies on.
    public function test_actors_match_type_detects_a_mismatch_on_an_unsaved_instance(): void
    {
        // Direct property assignment, never mass assignment -- the model has
        // no $fillable at all, matching the single-writer discipline
        // DeviceCustodyService itself already follows.
        $transfer = new DeviceCustodyTransfer;
        $transfer->transfer_type = CustodyTransferType::OwnerToGamePek;
        $transfer->from_actor_type = CustodyActor::Customer;
        $transfer->to_actor_type = CustodyActor::GamePek;

        $this->assertFalse($transfer->actorsMatchType());

        $matching = new DeviceCustodyTransfer;
        $matching->transfer_type = CustodyTransferType::OwnerToGamePek;
        $matching->from_actor_type = CustodyActor::Owner;
        $matching->to_actor_type = CustodyActor::GamePek;

        $this->assertTrue($matching->actorsMatchType());
    }
}
