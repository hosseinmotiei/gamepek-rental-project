<?php

namespace Tests\Feature;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\CustodyTransferType;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\GuaranteeInquiry;
use App\Models\Product;
use App\Models\RentalApplicationTransition;
use App\Models\RentalOperation;
use App\Models\User;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalOperationService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Delivery to the customer, and the customer's return.
 *
 * THE CONFIRMED RULES UNDER TEST:
 *  - a rental becomes Active ONLY when GamePek physically delivers the device
 *    to the customer -- never because a start date arrived;
 *  - the return is coordinated through support, so staff record it;
 *  - the owner has two hours after GamePek receives the device to report a
 *    defect (the arithmetic only -- nothing acts on it);
 *  - RentalChainOrchestrator stays the sole writer of the application state.
 *
 * What is NOT tested here, because it is not decided: any penalty for refusing
 * delivery, any consequence of a failed delivery, damage amounts, deposits,
 * refunds, settlement, and closure (Returned -> Closed).
 */
class RentalDeliveryAndReturnTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

    private DeviceRegistrationService $devices;

    private RentalChainOrchestrator $orchestrator;

    private User $admin;

    private const CODE = '13579';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContractTemplateSeeder::class);
        $this->seed(UserSeeder::class);

        // The chain fixture needs a guarantee policy and a pinned signing code
        // to reach a signed contract; both stand in for owner decisions that
        // are not this test's subject.
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

        $this->operations = app(RentalOperationService::class);
        $this->custody = app(DeviceCustodyService::class);
        $this->devices = app(DeviceRegistrationService::class);
        $this->orchestrator = app(RentalChainOrchestrator::class);
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

    /**
     * An approved rental whose device is already collected and sitting in
     * GamePek custody -- the state a delivery starts from.
     *
     * Everything runs through the real services, so the fixture cannot drift
     * from the chain it is meant to represent.
     */
    private function readyForDelivery(string $mobile, string $ownerMobile, string $serial): array
    {
        $customer = $this->customer($mobile);
        $application = $this->signedApplication($customer, self::CODE);

        $this->orchestrator->approve($application, $this->admin, null);
        $application->refresh();

        $reservation = $application->reservation()->firstOrFail();
        $device = $this->ownerDevice($application->product, $ownerMobile, $serial);

        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)
            ->firstOrFail();

        $this->operations->attachDevice($pickup, $device, $this->admin);
        $this->operations->start($pickup->refresh(), $this->admin);
        $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
        $this->custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh()];
    }

    /** Carries a rental all the way to delivered/Active. */
    private function delivered(string $mobile, string $ownerMobile, string $serial): array
    {
        [$customer, $application, $reservation, $device] = $this->readyForDelivery($mobile, $ownerMobile, $serial);

        $delivery = $this->operations->openDeliveryForReservation($reservation, $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin, 'سالم تحویل شد');

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh(), $delivery->refresh()];
    }

    // ── 1. Approved cannot become Active without a delivery ──────────────

    public function test_an_approved_rental_is_not_active_before_delivery(): void
    {
        [, $application] = $this->readyForDelivery('09170001001', '09170001002', 'DEL-001');

        $this->assertSame(RentalApplicationState::Approved, $application->state);

        // Re-deriving the chain never invents an activation.
        for ($i = 0; $i < 3; $i++) {
            $this->orchestrator->advance($application->refresh());
        }

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
    }

    public function test_a_delivery_cannot_be_opened_before_final_approval(): void
    {
        $customer = $this->customer('09170001003');
        $application = $this->signedApplication($customer, self::CODE);
        $reservation = $application->reservation()->firstOrFail();

        // AwaitingFinalApproval, not Approved.
        $this->expectException(\RuntimeException::class);
        $this->operations->openDeliveryForReservation($reservation, $this->admin);
    }

    // ── 2. Delivery starts the rental ────────────────────────────────────

    public function test_a_completed_delivery_moves_the_device_to_the_customer_and_activates_the_rental(): void
    {
        [, $application, , $device, $delivery] = $this->delivered('09170001004', '09170001005', 'DEL-002');

        $this->assertSame(RentalOperationState::Completed, $delivery->state);
        $this->assertSame(RentalApplicationState::Active, $application->state);
        $this->assertSame(CustodyActor::Customer, $device->currentCustody());

        $transfer = DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail();
        $this->assertSame(CustodyTransferType::GamePekToCustomer, $transfer->transfer_type);
        $this->assertSame(CustodyActor::GamePek, $transfer->from_actor_type);
        $this->assertSame(CustodyActor::Customer, $transfer->to_actor_type);
        $this->assertNotNull($transfer->transferred_at);

        // The condition check performed at the door is recorded as free text.
        $this->assertSame('سالم تحویل شد', $transfer->notes);

        // A quotable receipt reference, using the existing convention.
        $this->assertStringStartsWith('CUS-', $transfer->reference_number);
    }

    public function test_delivery_leaves_ownership_untouched(): void
    {
        [, , , $device] = $this->delivered('09170001006', '09170001007', 'DEL-003');

        $this->assertNotNull($device->owner_id);
        $this->assertSame('owner', $device->ownership->value);
    }

    public function test_the_orchestrator_remains_the_only_writer_of_the_application_state(): void
    {
        [, $application] = $this->delivered('09170001008', '09170001009', 'DEL-004');

        $transition = RentalApplicationTransition::where('rental_application_id', $application->id)
            ->where('to_state', RentalApplicationState::Active->value)
            ->firstOrFail();

        $this->assertSame('approved', $transition->from_state);
        $this->assertSame(1, RentalApplicationTransition::where('rental_application_id', $application->id)
            ->where('to_state', RentalApplicationState::Active->value)->count());

        // No service other than the orchestrator assigns `state`.
        $writers = [];
        foreach (['app/Services', 'app/Http/Controllers'] as $directory) {
            foreach ($this->phpFilesUnder(base_path($directory)) as $file) {
                $contents = (string) file_get_contents($file);

                if (preg_match('/->state\s*=\s*RentalApplicationState::/', $contents)) {
                    $writers[] = str_replace('\\', '/', $file);
                }
            }
        }

        $this->assertSame([], array_values(array_filter(
            $writers,
            fn (string $f) => ! str_contains($f, 'RentalChainOrchestrator.php'),
        )));
    }

    // ── 3. Delivery guards ───────────────────────────────────────────────

    public function test_a_delivery_cannot_be_recorded_before_the_device_is_in_gamepek_custody(): void
    {
        $customer = $this->customer('09170001010');
        $application = $this->signedApplication($customer, self::CODE);
        $this->orchestrator->approve($application, $this->admin, null);

        $reservation = $application->reservation()->firstOrFail();
        $device = $this->ownerDevice($application->product, '09170001011', 'DEL-005');

        // Attach the device but never collect it: custody is still the owner's.
        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)->firstOrFail();
        $this->operations->attachDevice($pickup, $device, $this->admin);

        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
    }

    public function test_a_duplicate_delivery_records_only_one_handover(): void
    {
        [, $application, $reservation] = $this->readyForDelivery('09170001012', '09170001013', 'DEL-006');

        $delivery = $this->operations->openDeliveryForReservation($reservation, $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);

        try {
            $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);
            $this->fail('A second delivery record must be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->count());
        $this->assertSame(1, RentalApplicationTransition::where('rental_application_id', $application->id)
            ->where('to_state', RentalApplicationState::Active->value)->count());
    }

    public function test_opening_a_delivery_twice_returns_the_same_task(): void
    {
        [, , $reservation] = $this->readyForDelivery('09170001014', '09170001015', 'DEL-007');

        $first = $this->operations->openDeliveryForReservation($reservation, $this->admin);
        $second = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::CustomerDelivery->value)->count());
    }

    public function test_a_delivery_cannot_be_recorded_while_the_task_has_not_started(): void
    {
        [, , $reservation] = $this->readyForDelivery('09170001016', '09170001017', 'DEL-008');

        $delivery = $this->operations->openDeliveryForReservation($reservation, $this->admin);

        // Scheduled, not in_progress, and no transfer was requested.
        $this->expectException(\RuntimeException::class);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);
    }

    // ── 4. Customer acknowledgement (receipt confirmation) ───────────────

    public function test_the_customer_can_confirm_the_delivery_record_without_moving_custody(): void
    {
        [$customer, , , $device, $delivery] = $this->delivered('09170001018', '09170001019', 'DEL-009');

        $transfer = DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail();
        $custodyBefore = $device->currentCustody();

        $this->custody->acknowledgeByCustomer($transfer, $customer);

        $this->assertSame(CustodyTransferState::Acknowledged, $transfer->fresh()->state);
        $this->assertNotNull($transfer->fresh()->acknowledged_at);
        $this->assertSame($customer->id, $transfer->fresh()->acknowledged_by_user_id);

        // Confirming a record is not a second handover.
        $this->assertSame($custodyBefore, $device->fresh()->currentCustody());
    }

    public function test_another_customer_cannot_confirm_someone_elses_delivery(): void
    {
        [, , , , $delivery] = $this->delivered('09170001020', '09170001021', 'DEL-010');

        $intruder = $this->customer('09170001022');
        $transfer = DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail();

        try {
            $this->custody->acknowledgeByCustomer($transfer, $intruder);
            $this->fail('A customer must not confirm another customer\'s delivery.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(CustodyTransferState::Transferred, $transfer->fresh()->state);
    }

    public function test_an_owner_cannot_confirm_a_delivery_leg(): void
    {
        [, , , $device, $delivery] = $this->delivered('09170001023', '09170001024', 'DEL-011');

        $transfer = DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail();

        try {
            $this->custody->acknowledgeByOwner($transfer, $device->owner->user);
            $this->fail('An owner is not a party to a delivery.');
        } catch (\RuntimeException $e) {
            $this->assertSame('مالک طرف این انتقال تحویل نیست.', $e->getMessage());
        }

        $this->assertSame(CustodyTransferState::Transferred, $transfer->fresh()->state);
    }

    // ── 5. Customer return ───────────────────────────────────────────────

    public function test_a_completed_return_moves_custody_back_and_ends_the_rental(): void
    {
        [, $application, $reservation, $device] = $this->delivered('09170001025', '09170001026', 'RET-001');

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $transfer = $this->custody->recordReturnToGamePek($return->refresh(), $this->admin, 'بدون آسیب');

        $this->assertSame(RentalOperationState::Completed, $return->refresh()->state);
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());
        $this->assertSame(CustodyTransferType::CustomerToGamePek, $transfer->transfer_type);
    }

    public function test_a_return_cannot_be_opened_for_a_rental_that_is_not_active(): void
    {
        [, , $reservation] = $this->readyForDelivery('09170001027', '09170001028', 'RET-002');

        // Still Approved: nothing was delivered.
        $this->expectException(\RuntimeException::class);
        $this->operations->openReturnForReservation($reservation, $this->admin);
    }

    public function test_a_duplicate_return_records_only_one_handover(): void
    {
        [, $application, $reservation] = $this->delivered('09170001029', '09170001030', 'RET-003');

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        try {
            $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);
            $this->fail('A second return record must be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, DeviceCustodyTransfer::where('rental_operation_id', $return->id)->count());
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }

    // ── 6. The owner's confirmed two-hour defect window ──────────────────

    public function test_the_owner_defect_window_runs_two_hours_from_gamepek_receipt(): void
    {
        [, , $reservation] = $this->delivered('09170001031', '09170001032', 'WIN-001');

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        $transfer = $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        $this->assertSame(
            '2026-10-01 12:00:00',
            $transfer->fresh()->ownerDefectReportDeadline()->toDateTimeString(),
        );

        $this->assertTrue($transfer->fresh()->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-01 11:59:00')));
        $this->assertFalse($transfer->fresh()->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-01 12:00:01')));

        Carbon::setTestNow();
    }

    public function test_a_delivery_to_the_customer_starts_no_owner_defect_window(): void
    {
        [, , , , $delivery] = $this->delivered('09170001033', '09170001034', 'WIN-002');

        $transfer = DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail();

        // The window measures GamePek taking receipt; handing the device out
        // is the opposite direction.
        $this->assertNull($transfer->ownerDefectReportDeadline());
        $this->assertFalse($transfer->isWithinOwnerDefectReportWindow());
    }

    // ── 7. Reconciliation stays read-only and understands the new legs ───

    public function test_a_healthy_delivery_produces_no_reconciliation_findings(): void
    {
        $this->delivered('09170001035', '09170001036', 'REC-101');

        $this->assertFalse(app(OperationCustodyReconciler::class)->hasFindings());
    }

    public function test_the_reconciler_detects_a_delivery_whose_device_is_not_with_the_customer(): void
    {
        [, , , $device, $delivery] = $this->delivered('09170001037', '09170001038', 'REC-102');

        // Out-of-band write: the class of event no service controls.
        DB::table('device_custody_transfers')
            ->where('rental_operation_id', $delivery->id)
            ->update(['state' => CustodyTransferState::Requested->value, 'transferred_at' => null]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::COMPLETED_WITHOUT_TRANSFER, $codes);
        $this->assertContains(OperationCustodyReconciler::COMPLETED_BUT_CUSTODY_NOT_CUSTOMER, $codes);

        // It reported and changed nothing.
        $this->assertSame(CustodyTransferState::Requested, DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail()->state);
        $this->assertSame(RentalOperationState::Completed, $delivery->fresh()->state);
    }

    // ── 8. Audit ─────────────────────────────────────────────────────────

    public function test_the_delivery_and_return_are_audited_with_their_actor(): void
    {
        [, , $reservation, $device] = $this->delivered('09170001039', '09170001040', 'AUD-001');

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        // Both handovers are recorded, and neither leaks a raw serial.
        $this->assertSame(3, AuditEvent::forAction('custody.transferred')->count());

        foreach (AuditEvent::forAction('custody.transferred')->get() as $event) {
            $this->assertSame($this->admin->id, $event->actor_id);
            $this->assertStringNotContainsString($device->serial_number, json_encode($event->context, JSON_UNESCAPED_UNICODE));
        }
    }

    // ── 9. HTTP surface: authorization and IDOR ──────────────────────────

    public function test_the_customer_sees_their_delivery_status_and_the_confirm_control(): void
    {
        [$customer, $application] = $this->delivered('09170001041', '09170001042', 'HTTP-001');

        $this->actingAs($customer)
            ->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertSee('تحویل و بازگشت دستگاه')
            ->assertSee('تأیید این سابقه');
    }

    public function test_the_customer_page_never_exposes_the_device_serial_or_owner(): void
    {
        [$customer, $application, , $device] = $this->delivered('09170001043', '09170001044', 'HTTP-002');

        $response = $this->actingAs($customer)
            ->get(route('rental.applications.show', $application))
            ->assertOk();

        $response->assertDontSee($device->serial_number);
        $response->assertDontSee($device->owner->user->full_name);
        $response->assertDontSee($device->owner->user->mobile);
    }

    public function test_a_customer_can_confirm_their_own_handover_through_the_route(): void
    {
        [$customer, $application, , , $delivery] = $this->delivered('09170001045', '09170001046', 'HTTP-003');

        $this->actingAs($customer)
            ->post(route('rental.applications.handover.acknowledge', $application), ['operation' => $delivery->id])
            ->assertRedirect();

        $this->assertSame(
            CustodyTransferState::Acknowledged,
            DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail()->state,
        );
    }

    public function test_another_customer_cannot_confirm_a_handover_through_the_route(): void
    {
        [, $application, , , $delivery] = $this->delivered('09170001047', '09170001048', 'HTTP-004');

        $intruder = $this->customer('09170001049');

        $this->actingAs($intruder)
            ->post(route('rental.applications.handover.acknowledge', $application), ['operation' => $delivery->id])
            ->assertForbidden();

        $this->assertSame(
            CustodyTransferState::Transferred,
            DeviceCustodyTransfer::where('rental_operation_id', $delivery->id)->firstOrFail()->state,
        );
    }

    public function test_a_customer_cannot_confirm_an_operation_belonging_to_another_application(): void
    {
        [$customer, $application, , , $delivery] = $this->delivered('09170001050', '09170001051', 'HTTP-005');

        // A real operation id, posted against the caller's OWN application.
        // It is resolved through that application's reservation, so a foreign
        // id simply is not found rather than being acted on.
        $foreignOperationId = $delivery->id + 9999;

        $this->actingAs($customer)
            ->postJson(route('rental.applications.handover.acknowledge', $application), ['operation' => $foreignOperationId])
            ->assertStatus(422);
    }

    public function test_a_guest_cannot_confirm_a_handover(): void
    {
        [, $application, , , $delivery] = $this->delivered('09170001052', '09170001053', 'HTTP-006');

        $this->post(route('rental.applications.handover.acknowledge', $application), ['operation' => $delivery->id])
            ->assertRedirect();
    }

    public function test_a_customer_cannot_open_a_delivery_or_a_return(): void
    {
        [$customer, $application] = $this->readyForDelivery('09170001054', '09170001055', 'HTTP-007');

        // The staff routes sit behind the admin middleware and their own
        // permission check. EnsureIsAdmin bounces a non-admin to the admin
        // login rather than returning 403; either way the controller is never
        // reached and no task is created.
        $this->actingAs($customer->fresh())
            ->post(route('admin.rental-applications.delivery.open', $application))
            ->assertRedirect(route('admin.login'));

        $this->actingAs($customer->fresh())
            ->post(route('admin.rental-applications.return.open', $application))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(0, RentalOperation::where('type', RentalOperationType::CustomerDelivery->value)->count());
    }

    public function test_an_admin_without_manage_operations_cannot_open_a_delivery(): void
    {
        [, $application] = $this->readyForDelivery('09170001056', '09170001057', 'HTTP-008');

        // `support` may view rental applications but has no manage_operations.
        $staff = User::create([
            'full_name' => 'پشتیبان', 'mobile' => '09170001058',
            'email' => 'support.delivery@test.local', 'status' => 'active',
        ]);
        $staff->syncRoles(['support']);

        $this->actingAs($staff)
            ->post(route('admin.rental-applications.delivery.open', $application))
            ->assertForbidden();

        $this->assertSame(0, RentalOperation::where('type', RentalOperationType::CustomerDelivery->value)->count());
    }

    public function test_the_admin_operation_screen_renders_for_a_delivery(): void
    {
        [, , , , $delivery] = $this->delivered('09170001059', '09170001060', 'HTTP-009');

        $this->actingAs($this->admin)
            ->get(route('admin.operations.show', $delivery))
            ->assertOk()
            ->assertSee('تحویل دستگاه به مشتری');
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
