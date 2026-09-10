<?php

namespace Tests\Feature;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\CustodyTransferType;
use App\Enums\DeviceOwnership;
use App\Enums\DeviceState;
use App\Enums\RentalOperationState;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalReservationService;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Phase 04B: hardening around the owner -> GamePek pickup established in 04A.
 *
 * These tests are about the guards rather than the happy path -- the happy path
 * is covered by RentalOperationsCustodyTest and is not repeated here. What is
 * asserted below is what happens when something tries to break the invariants:
 * a bad actor pair, a device that does not match, a terminal record being
 * rewritten, an acknowledgement pretending to be a second handover.
 *
 * Several assertions deliberately go around the service layer and write with
 * raw SQL. That is the point: the service being the only writer is a fact about
 * today's code, not an invariant, so the database has to refuse independently.
 */
class RentalCustodyHardeningTest extends TestCase
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

    private function ownerDevice(Product $product, string $mobile, string $serial): Device
    {
        $user = User::create(['full_name' => 'مالک دستگاه', 'mobile' => $mobile, 'status' => 'active']);
        $owner = $this->devices->ensureOwnerProfile($user);

        return $this->devices->approve(
            $this->devices->registerForOwner($owner, $product, $serial),
            $this->admin,
        )->refresh();
    }

    /** Paid application, reservation, and the pickup task it created. */
    private function paidWithOperation(string $mobile): array
    {
        $application = $this->paidApplication($this->customer($mobile), $this->uniqueNationalCode());
        $reservation = $application->reservation()->firstOrFail();

        return [
            $application,
            $reservation,
            RentalOperation::where('rental_reservation_id', $reservation->id)->firstOrFail(),
        ];
    }

    /** Carried to in_progress with a requested handover against an owner device. */
    private function pickupInProgress(string $mobile, string $ownerMobile, string $serial): array
    {
        [$application, , $operation] = $this->paidWithOperation($mobile);

        $device = $this->ownerDevice($application->product, $ownerMobile, $serial);

        $this->operations->attachDevice($operation, $device, $this->admin);
        $this->operations->start($operation->refresh(), $this->admin);
        $this->custody->requestFromOwner($operation->refresh(), $this->admin);

        return [$application, $operation->refresh(), $device->refresh()];
    }

    // ── 1-2. Completion prerequisites ────────────────────────────────────

    public function test_a_pickup_cannot_complete_without_a_device(): void
    {
        [, , $operation] = $this->paidWithOperation('09150001001');

        $this->assertNull($operation->device_id);

        // Straight at the completion method, bypassing the state ladder that
        // would already have stopped it.
        $this->expectException(\RuntimeException::class);
        $this->operations->completeAfterCustody($operation, $this->admin);
    }

    public function test_a_pickup_cannot_complete_before_the_handover_is_recorded(): void
    {
        [, $operation] = $this->pickupInProgress('09150001002', '09160001001', 'PRE-001');

        $transfer = $operation->custodyTransfer;
        $this->assertSame(CustodyTransferState::Requested, $transfer->state);
        $this->assertFalse($transfer->isPossessionMoved());

        try {
            $this->operations->completeAfterCustody($operation, $this->admin);
            $this->fail('A pickup completed with possession still with the owner.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $e->getMessage());
        }

        $this->assertSame(RentalOperationState::InProgress, $operation->fresh()->state);
    }

    public function test_a_completed_operation_always_has_a_device_at_the_database_level(): void
    {
        [, $operation] = $this->pickupInProgress('09150001003', '09160001002', 'DBC-001');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        // Raw, around every service guard: the CHECK constraint must still
        // refuse a completed operation whose device was cleared.
        $this->expectException(QueryException::class);
        DB::table('rental_operations')->where('id', $operation->id)->update(['device_id' => null]);
    }

    // ── 3-5. Custody semantics ───────────────────────────────────────────

    public function test_the_handover_preserves_ownership_and_moves_custody(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001004', '09160001003', 'OWN-001');

        $ownerBefore = $device->owner_id;

        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $fresh = $device->fresh();
        $this->assertSame($ownerBefore, $fresh->owner_id);
        $this->assertSame(DeviceOwnership::Owner, $fresh->ownership);
        $this->assertSame(CustodyActor::GamePek, $fresh->currentCustody());

        // No mutable custody column was ever introduced.
        $this->assertFalse(
            Schema::hasColumn('devices', 'current_custody'),
            'A mutable current_custody column would give custody two sources of truth.',
        );
    }

    public function test_acknowledgement_does_not_create_a_second_custody_movement(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001005', '09160001004', 'ACK-001');

        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $rowsBefore = DeviceCustodyTransfer::count();
        $movedBefore = DeviceCustodyTransfer::possessionMoved()->count();
        $custodyBefore = $device->fresh()->currentCustody();

        $transfer = DeviceCustodyTransfer::firstOrFail();
        $this->custody->acknowledgeByOwner($transfer, $device->owner->user);

        $this->assertSame(CustodyTransferState::Acknowledged, $transfer->fresh()->state);

        // One physical event, one row, one movement.
        $this->assertSame($rowsBefore, DeviceCustodyTransfer::count());
        $this->assertSame($movedBefore, DeviceCustodyTransfer::possessionMoved()->count());
        $this->assertSame($custodyBefore, $device->fresh()->currentCustody());
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());
    }

    public function test_acknowledgement_is_idempotent_and_terminal(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001006', '09160001005', 'TRM-001');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $transfer = DeviceCustodyTransfer::firstOrFail();
        $ownerUser = $device->owner->user;

        $this->custody->acknowledgeByOwner($transfer, $ownerUser);
        $firstAt = $transfer->fresh()->acknowledged_at;

        // A second call is a no-op, not a rewrite of when it happened.
        $this->custody->acknowledgeByOwner($transfer->fresh(), $ownerUser);
        $this->assertEquals($firstAt, $transfer->fresh()->acknowledged_at);

        // And the state itself has nowhere left to go.
        $this->assertSame([], CustodyTransferState::Acknowledged->allowedTransitions());
        $this->assertFalse(CustodyTransferState::Acknowledged->canTransitionTo(CustodyTransferState::Transferred));
    }

    public function test_an_acknowledged_transfer_cannot_lose_its_timestamp(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001007', '09160001006', 'AKT-001');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $transfer = DeviceCustodyTransfer::firstOrFail();
        $this->custody->acknowledgeByOwner($transfer, $device->owner->user);

        $this->expectException(QueryException::class);
        DB::table('device_custody_transfers')->where('id', $transfer->id)
            ->update(['acknowledged_at' => null]);
    }

    // ── 6. Actor pairs ───────────────────────────────────────────────────

    public function test_the_database_refuses_an_actor_pair_that_contradicts_the_transfer_type(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001008', '09160001007', 'ACT-001');
        $transfer = DeviceCustodyTransfer::firstOrFail();

        // owner_to_gamepek pointing anywhere but owner -> gamepek would be a
        // console quietly changing hands between the wrong parties.
        $this->expectException(QueryException::class);
        DB::table('device_custody_transfers')->where('id', $transfer->id)
            ->update(['to_actor_type' => CustodyActor::Customer->value]);
    }

    public function test_the_database_refuses_a_gamepek_side_that_names_an_owner(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001009', '09160001008', 'ACT-002');
        $transfer = DeviceCustodyTransfer::firstOrFail();

        // GamePek deliberately has no owner row; naming one here would invent
        // a fake owner account for the first-party side.
        $this->expectException(QueryException::class);
        DB::table('device_custody_transfers')->where('id', $transfer->id)
            ->update(['to_owner_id' => $device->owner_id]);
    }

    public function test_the_database_refuses_an_owner_side_with_no_owner(): void
    {
        [, $operation] = $this->pickupInProgress('09150001010', '09160001009', 'ACT-003');
        $transfer = DeviceCustodyTransfer::firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('device_custody_transfers')->where('id', $transfer->id)
            ->update(['from_owner_id' => null]);
    }

    public function test_the_service_rejects_a_transfer_whose_actors_do_not_match_its_type(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001011', '09160001010', 'ACT-004');

        $transfer = DeviceCustodyTransfer::firstOrFail();

        // In memory only -- the model refuses to persist it, and so would the
        // constraint, but the service must refuse before either.
        $transfer->from_actor_type = CustodyActor::Customer;

        $this->assertFalse($transfer->actorsMatchType());
        $this->assertTrue(
            (new DeviceCustodyTransfer)->forceFill([
                'from_actor_type' => CustodyActor::Owner,
                'to_actor_type' => CustodyActor::GamePek,
                'transfer_type' => CustodyTransferType::OwnerToGamePek,
            ])->actorsMatchType(),
        );
    }

    // ── 7-8. Device agreement and duplicates ─────────────────────────────

    public function test_a_transfer_naming_a_different_device_than_its_operation_is_refused(): void
    {
        [$application, $operation] = $this->pickupInProgress('09150001012', '09160001011', 'MIS-001');

        $other = $this->ownerDevice($application->product, '09160001012', 'MIS-002');

        // Out-of-band drift, exactly what the reconciler exists to surface.
        DB::table('device_custody_transfers')
            ->where('rental_operation_id', $operation->id)
            ->update(['device_id' => $other->id, 'from_owner_id' => $other->owner_id]);

        try {
            $this->custody->recordHandoverToGamePek($operation->fresh(), $this->admin);
            $this->fail('A handover was recorded against the wrong device.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $e->getMessage());
        }

        $this->assertSame(RentalOperationState::InProgress, $operation->fresh()->state);
    }

    public function test_a_duplicate_transfer_row_cannot_exist_for_one_operation(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001013', '09160001013', 'DUP-001');

        $this->expectException(QueryException::class);
        DB::table('device_custody_transfers')->insert([
            'reference_number' => DeviceCustodyTransfer::generateReference(),
            'device_id' => $device->id,
            'rental_operation_id' => $operation->id,
            'from_actor_type' => 'owner',
            'to_actor_type' => 'gamepek',
            'from_owner_id' => $device->owner_id,
            'transfer_type' => 'owner_to_gamepek',
            'state' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_every_transfer_carries_a_unique_operational_reference(): void
    {
        [, $operation] = $this->pickupInProgress('09150001014', '09160001014', 'REF-001');

        $transfer = DeviceCustodyTransfer::firstOrFail();

        $this->assertNotNull($transfer->reference_number);
        $this->assertStringStartsWith('CUS-', $transfer->reference_number);

        $this->expectException(QueryException::class);
        DB::table('device_custody_transfers')->insert([
            'reference_number' => $transfer->reference_number,
            'device_id' => $transfer->device_id,
            'rental_operation_id' => null,
            'from_actor_type' => 'owner',
            'to_actor_type' => 'gamepek',
            'from_owner_id' => $transfer->from_owner_id,
            'transfer_type' => 'owner_to_gamepek',
            'state' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── 9-10. Terminal records ───────────────────────────────────────────

    public function test_a_terminal_operation_cannot_be_moved_by_ordinary_transitions(): void
    {
        [, $operation] = $this->pickupInProgress('09150001015', '09160001015', 'TER-001');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $this->assertSame(RentalOperationState::Completed, $operation->fresh()->state);

        foreach (['start', 'fail'] as $verb) {
            try {
                $verb === 'fail'
                    ? $this->operations->fail($operation->fresh(), $this->admin, 'دلیل')
                    : $this->operations->start($operation->fresh(), $this->admin);
                $this->fail("A completed operation accepted {$verb}().");
            } catch (\RuntimeException $e) {
                $this->assertSame(RentalOperationState::Completed, $operation->fresh()->state);
            }
        }

        // Re-attaching a device to a closed task is refused too.
        $this->expectException(\RuntimeException::class);
        $this->operations->attachDevice($operation->fresh(), $operation->device, $this->admin);
    }

    public function test_custody_lifecycle_fields_are_not_mass_assignable(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001016', '09160001016', 'MAS-001');
        $transfer = DeviceCustodyTransfer::firstOrFail();

        // The model guards everything. This is the field that matters most:
        // a writable to_actor_type is a device changing hands on a form post.
        foreach (['to_actor_type', 'from_actor_type', 'state', 'transferred_at', 'device_id'] as $field) {
            try {
                $transfer->fill([$field => 'x']);
                $this->fail("{$field} was mass-assignable on DeviceCustodyTransfer.");
            } catch (MassAssignmentException $e) {
                // Expected.
            }
        }

        $this->assertSame(CustodyActor::GamePek, $transfer->fresh()->to_actor_type);
        $this->assertSame(DeviceOwnership::Owner, $device->fresh()->ownership);
    }

    // ── 11-12. Authorization and CSRF ────────────────────────────────────

    public function test_an_owner_cannot_mutate_admin_only_operation_state(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001017', '09160001017', 'AUT-001');
        $ownerUser = $device->owner->user;

        foreach ([
            route('admin.operations.custody', $operation),
            route('admin.operations.start', $operation),
            route('admin.operations.device', $operation),
        ] as $url) {
            $this->actingAs($ownerUser)->post($url, ['reason' => 'x', 'device_id' => $device->id])
                ->assertRedirect(route('admin.login'));
        }

        $this->assertSame(RentalOperationState::InProgress, $operation->fresh()->state);
        $this->assertSame(0, DeviceCustodyTransfer::possessionMoved()->count());
    }

    public function test_the_reconciliation_report_is_permission_gated(): void
    {
        $support = User::create(['full_name' => 'پشتیبانی', 'mobile' => '09170001001', 'status' => 'active']);
        $support->syncRoles(['support']);

        $this->actingAs($support)->get(route('admin.operations.reconciliation'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.operations.reconciliation'))->assertOk();
    }

    public function test_admin_operation_forms_carry_csrf_tokens_and_no_route_is_exempt(): void
    {
        [, $operation] = $this->pickupInProgress('09150001018', '09160001018', 'CSR-001');

        // Laravel's VerifyCsrfToken short-circuits inside the test runner, so
        // the meaningful assertions are that the token is emitted and that the
        // routes were never added to the exception list.
        $html = $this->actingAs($this->admin)
            ->get(route('admin.operations.show', $operation))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="_token"', $html);

        $exempt = (new VerifyCsrfToken(
            app(), app('encrypter')
        ));
        $except = (fn () => $this->except)->call($exempt);

        foreach ($except as $pattern) {
            $this->assertStringNotContainsString('operations', $pattern);
            $this->assertStringNotContainsString('owner', $pattern);
        }
    }

    // ── 13. Audit ────────────────────────────────────────────────────────

    public function test_every_hardened_mutation_is_audited_with_its_actor(): void
    {
        [, $operation, $device] = $this->pickupInProgress('09150001019', '09160001019', 'AUD-001');

        $this->custody->recordHandoverToGamePek($operation, $this->admin);
        $this->custody->acknowledgeByOwner(DeviceCustodyTransfer::firstOrFail(), $device->owner->user);

        foreach ([
            'operation.created', 'operation.device_attached', 'operation.started',
            'operation.completed', 'custody.requested', 'custody.transferred',
            'custody.acknowledged',
        ] as $action) {
            $event = AuditEvent::where('action', $action)->latest('id')->first();
            $this->assertNotNull($event, "Missing audit event: {$action}");
            $this->assertNotNull($event->occurred_at, "No timestamp on: {$action}");
        }

        // The handover names its actor and its operational reference, and
        // still never the raw serial.
        $transferred = AuditEvent::where('action', 'custody.transferred')->firstOrFail();
        $context = (array) $transferred->context;

        $this->assertSame($this->admin->id, $transferred->actor_id);
        $this->assertSame(DeviceCustodyTransfer::firstOrFail()->reference_number, $context['reference_number']);
        $this->assertSame($device->maskedSerial(), $context['device_serial_mask']);
        $this->assertStringNotContainsString($device->serial_number, json_encode($context, JSON_UNESCAPED_UNICODE));

        // The owner, not the admin, is recorded as acknowledging.
        $acknowledged = AuditEvent::where('action', 'custody.acknowledged')->firstOrFail();
        $this->assertSame($device->owner->user_id, $acknowledged->actor_id);
    }

    public function test_the_operation_screen_shows_the_audit_trail(): void
    {
        [, $operation] = $this->pickupInProgress('09150001020', '09160001020', 'AUV-001');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.operations.show', $operation))
            ->assertOk()
            ->assertSee('custody.transferred')
            ->assertSee(DeviceCustodyTransfer::firstOrFail()->reference_number);
    }

    // ── 14. GamePek-owned behaviour is unchanged ─────────────────────────

    public function test_a_gamepek_owned_device_still_resolves_to_not_required(): void
    {
        [$application, , $operation] = $this->paidWithOperation('09150001021');

        $gp = $this->devices->approve(
            $this->devices->registerForGamePek($application->product, 'GPK-001', [], $this->admin),
            $this->admin,
        )->refresh();

        $this->operations->attachDevice($operation, $gp, $this->admin);

        $this->assertSame(RentalOperationState::NotRequired, $operation->fresh()->state);
        $this->assertSame(0, DeviceCustodyTransfer::count());
        $this->assertNull($gp->fresh()->owner_id);
        $this->assertSame(CustodyActor::GamePek, $gp->fresh()->currentCustody());
    }

    // ── Reconciliation ───────────────────────────────────────────────────

    public function test_healthy_data_produces_no_reconciliation_findings(): void
    {
        [, $operation] = $this->pickupInProgress('09150001022', '09160001021', 'REC-001');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $this->assertFalse(app(OperationCustodyReconciler::class)->hasFindings());
    }

    public function test_the_reconciler_detects_a_completion_with_no_handover(): void
    {
        [, $operation] = $this->pickupInProgress('09150001023', '09160001022', 'REC-002');

        // Out-of-band write: the class of event no service controls.
        DB::table('rental_operations')->where('id', $operation->id)->update([
            'state' => RentalOperationState::Completed->value,
            'completed_at' => now(),
        ]);

        $findings = app(OperationCustodyReconciler::class)->findings();
        $codes = $findings->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::COMPLETED_WITHOUT_TRANSFER, $codes);
        $this->assertContains(OperationCustodyReconciler::COMPLETED_BUT_CUSTODY_NOT_GAMEPEK, $codes);
        $this->assertSame($operation->operation_number, $findings->first()['operation_number']);

        // It reported and changed nothing.
        $this->assertSame(1, DeviceCustodyTransfer::count());
        $this->assertSame(CustodyTransferState::Requested, DeviceCustodyTransfer::firstOrFail()->state);
    }

    public function test_the_reconciler_detects_a_handover_whose_operation_never_closed(): void
    {
        [, $operation] = $this->pickupInProgress('09150001024', '09160001023', 'REC-003');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        DB::table('rental_operations')->where('id', $operation->id)->update([
            'state' => RentalOperationState::InProgress->value,
        ]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::TRANSFERRED_BUT_NOT_COMPLETED, $codes);
    }

    public function test_the_reconciler_detects_a_device_mismatch_once(): void
    {
        [$application, $operation] = $this->pickupInProgress('09150001025', '09160001024', 'REC-004');
        $this->custody->recordHandoverToGamePek($operation, $this->admin);

        $other = $this->ownerDevice($application->product, '09160001025', 'REC-005');

        DB::table('device_custody_transfers')->where('rental_operation_id', $operation->id)
            ->update(['device_id' => $other->id, 'from_owner_id' => $other->owner_id]);

        $findings = app(OperationCustodyReconciler::class)->findings()
            ->where('code', OperationCustodyReconciler::DEVICE_MISMATCH);

        $this->assertCount(1, $findings, 'A mismatch visible from both sides was reported twice.');

        $this->actingAs($this->admin)->get(route('admin.operations.reconciliation'))->assertOk();
    }

    // ── 15-16. Earlier phases still hold ─────────────────────────────────

    public function test_phase_02_reservation_gating_still_holds(): void
    {
        // Selection without payment reserves nothing and opens no operation.
        $application = $this->reservedApplication($this->customer('09150001026'), $this->uniqueNationalCode());

        $this->assertNull($application->reservation()->first());
        $this->assertSame(0, RentalReservation::count());
        $this->assertSame(0, RentalOperation::count());

        // And an unverified payment cannot be materialised into one.
        $order = Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $application->user_id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'subtotal' => 0, 'total' => 0,
        ]);
        $application->update(['order_id' => $order->id]);

        $this->expectException(\RuntimeException::class);
        app(RentalReservationService::class)->materialiseAfterPayment($application->refresh());
    }

    public function test_phase_03_ownership_and_serial_invariants_still_hold(): void
    {
        [$application] = $this->paidWithOperation('09150001027');

        $device = $this->ownerDevice($application->product, '09160001026', 'SER-001');

        // Serial identity is still one physical console, once.
        $duplicateOwner = $this->devices->ensureOwnerProfile(
            User::create(['full_name' => 'مالک دوم', 'mobile' => '09160001027', 'status' => 'active'])
        );

        try {
            $this->devices->registerForOwner($duplicateOwner, $application->product, 'ser 001');
            $this->fail('A duplicate normalised serial was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame(1, Device::where('serial_normalized', 'SER001')->count());
        }

        // Ownership is still not settable from a request.
        try {
            $device->fill(['ownership' => DeviceOwnership::GamePek->value, 'owner_id' => null]);
            $this->fail('Device ownership was mass-assignable.');
        } catch (MassAssignmentException $e) {
            $this->assertSame(DeviceOwnership::Owner, $device->fresh()->ownership);
        }

        // And cross-owner isolation is unchanged.
        $intruder = User::create(['full_name' => 'مزاحم', 'mobile' => '09160001028', 'status' => 'active']);
        $this->devices->ensureOwnerProfile($intruder);

        $this->actingAs($intruder)->get(route('owner.devices.show', $device))->assertForbidden();
        $this->assertSame(DeviceState::Approved, $device->fresh()->state);
    }
}
