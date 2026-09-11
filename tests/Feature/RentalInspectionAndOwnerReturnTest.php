<?php

namespace Tests\Feature;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\CustodyTransferType;
use App\Enums\DeviceOwnership;
use App\Enums\RentalApplicationState;
use App\Enums\RentalInspectionStage;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\GuaranteeInquiry;
use App\Models\Product;
use App\Models\RentalApplicationTransition;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Inspection evidence, customer-return hardening, the owner's two-hour window,
 * and the final GamePek -> owner leg.
 *
 * CONFIRMED RULES UNDER TEST:
 *  - the customer returns the device to GamePek (C-37); the device then
 *    eventually goes back to its owner (C-40) -- never customer -> owner;
 *  - the owner has two hours from GamePek's receipt of the returned device to
 *    report a defect (C-38) -- arithmetic only;
 *  - GamePek's expert determines damage (C-39) -- recorded as free-text
 *    evidence; no amount, grade or formula exists to test.
 *
 * NOT tested because NOT decided: damage pricing, deposit, refund, settlement,
 * closure (Returned -> Closed), and anything that follows the window closing.
 */
class RentalInspectionAndOwnerReturnTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

    private DeviceRegistrationService $devices;

    private RentalChainOrchestrator $orchestrator;

    private RentalInspectionService $inspections;

    private User $admin;

    private const CODE = '13579';

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

        $this->operations = app(RentalOperationService::class);
        $this->custody = app(DeviceCustodyService::class);
        $this->devices = app(DeviceRegistrationService::class);
        $this->orchestrator = app(RentalChainOrchestrator::class);
        $this->inspections = app(RentalInspectionService::class);
        $this->admin = User::where('mobile', '09100000001')->firstOrFail();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function ownerDevice(Product $product, string $mobile, string $serial): Device
    {
        $user = User::create(['full_name' => 'مالک '.$serial, 'mobile' => $mobile, 'status' => 'active']);
        $owner = $this->devices->ensureOwnerProfile($user);

        return $this->devices->approve(
            $this->devices->registerForOwner($owner, $product, $serial),
            $this->admin,
        )->refresh();
    }

    private function gamePekDevice(Product $product, string $serial): Device
    {
        return $this->devices->approve(
            $this->devices->registerForGamePek($product, $serial, [], $this->admin),
            $this->admin,
        )->refresh();
    }

    private function pickupFor(RentalReservation $reservation): RentalOperation
    {
        return RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)
            ->firstOrFail();
    }

    /**
     * An approved rental whose device GamePek already holds. With an owner
     * device the real pickup runs; with GamePek stock it resolves to
     * not_required, exactly as in production.
     */
    private function readyForDelivery(string $mobile, ?string $ownerMobile, string $serial): array
    {
        $customer = $this->customer($mobile);
        $application = $this->signedApplication($customer, self::CODE);

        $this->orchestrator->approve($application, $this->admin, null);
        $application->refresh();

        $reservation = $application->reservation()->firstOrFail();
        $device = $ownerMobile === null
            ? $this->gamePekDevice($application->product, $serial)
            : $this->ownerDevice($application->product, $ownerMobile, $serial);

        $pickup = $this->pickupFor($reservation);
        $this->operations->attachDevice($pickup, $device, $this->admin);

        if ($ownerMobile !== null) {
            $this->operations->start($pickup->refresh(), $this->admin);
            $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
            $this->custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);
        }

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh()];
    }

    private function delivered(string $mobile, ?string $ownerMobile, string $serial): array
    {
        [$customer, $application, $reservation, $device] = $this->readyForDelivery($mobile, $ownerMobile, $serial);

        $delivery = $this->operations->openDeliveryForReservation($reservation, $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin, 'سالم تحویل شد');

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh(), $delivery->refresh()];
    }

    /** Carries a rental through the customer's return: application Returned. */
    private function returned(string $mobile, ?string $ownerMobile, string $serial): array
    {
        [$customer, $application, $reservation, $device, $delivery] = $this->delivered($mobile, $ownerMobile, $serial);

        $return = $this->operations->openReturnForReservation($reservation, $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin, 'دریافت شد');

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh(), $delivery->refresh(), $return->refresh()];
    }

    /** Carries an owner device all the way home. */
    private function returnedToOwner(string $mobile, string $ownerMobile, string $serial): array
    {
        [$customer, $application, $reservation, $device, $delivery, $return] = $this->returned($mobile, $ownerMobile, $serial);

        $ownerReturn = $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);
        $this->custody->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        $transfer = $this->custody->recordReturnToOwner($ownerReturn->refresh(), $this->admin);

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh(), $ownerReturn->refresh(), $transfer, $return];
    }

    /**
     * A second, paid rental of the SAME console for a later, non-overlapping
     * date range. The chain fixture gives every application its own product
     * and the same dates, so both are moved out-of-band -- the only way to
     * put one physical device into two sequential rentals in a test.
     */
    private function laterRentalOfSameDevice(Device $device, RentalReservation $first, string $mobile): RentalReservation
    {
        $application = $this->paidApplication($this->customer($mobile), $this->uniqueNationalCode());
        $reservation = $application->reservation()->firstOrFail();

        DB::table('rental_reservations')->where('id', $reservation->id)->update([
            'product_id' => $device->product_id,
            'start_date' => $first->end_date->copy()->addDays(10)->toDateString(),
            'end_date' => $first->end_date->copy()->addDays(12)->toDateString(),
        ]);

        return $reservation->refresh();
    }

    private function staff(string $mobile, string $email): User
    {
        $staff = User::create([
            'full_name' => 'پشتیبان', 'mobile' => $mobile, 'email' => $email, 'status' => 'active',
        ]);
        $staff->syncRoles(['support']);

        return $staff;
    }

    // ── 1. Customer return hardening ─────────────────────────────────────

    public function test_opening_a_return_twice_yields_one_task(): void
    {
        [, , $reservation] = $this->delivered('09170002001', '09170002002', 'RH-001');

        $first = $this->operations->openReturnForReservation($reservation, $this->admin);
        $second = $this->operations->openReturnForReservation($reservation, $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RentalOperation::where('type', RentalOperationType::CustomerReturn->value)->count());
    }

    public function test_the_database_refuses_a_second_handover_row_for_one_return(): void
    {
        [, , , , , $return] = $this->returned('09170002003', '09170002004', 'RH-002');

        $row = (array) DB::table('device_custody_transfers')->where('rental_operation_id', $return->id)->first();
        unset($row['id']);
        $row['reference_number'] = 'CUS-DUPLICATE-1';

        // The concurrency backstop: whatever races the application layer
        // loses, unique(rental_operation_id) still admits one handover.
        try {
            DB::table('device_custody_transfers')->insert($row);
            $this->fail('A second handover for one return must be refused by the database.');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1] ?? null);
        }

        $this->assertSame(1, DeviceCustodyTransfer::where('rental_operation_id', $return->id)->count());
    }

    public function test_a_failed_return_can_be_retried_and_still_records_one_handover(): void
    {
        [, $application, $reservation] = $this->delivered('09170002005', '09170002006', 'RH-003');

        $return = $this->operations->openReturnForReservation($reservation, $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $opened = $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->operations->fail($return->refresh(), $this->admin, 'مشتری در محل نبود');

        // Still Active: a failed return changes no application state.
        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);

        $this->operations->start($return->refresh(), $this->admin);
        $reopened = $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        $this->assertSame($opened->id, $reopened->id);
        $this->assertSame(1, DeviceCustodyTransfer::where('rental_operation_id', $return->id)->count());
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }

    public function test_a_return_refuses_a_device_that_this_rental_did_not_deliver(): void
    {
        [, $application, $reservation, , $delivery] = $this->delivered('09170002007', '09170002008', 'RH-004');

        $return = $this->operations->openReturnForReservation($reservation, $this->admin);
        $this->operations->start($return->refresh(), $this->admin);

        // Out-of-band: the delivery record now claims to belong to a different
        // rental. The device is still "with a customer", but not because of
        // THIS rental -- cross-rental evidence must be refused.
        $other = $this->paidApplication($this->customer('09170002009'), $this->uniqueNationalCode())
            ->reservation()->firstOrFail();
        DB::table('device_custody_transfers')->where('rental_operation_id', $delivery->id)
            ->update(['rental_reservation_id' => $other->id]);

        try {
            $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
            $this->fail('A return must refuse a device this rental did not deliver.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('همین اجاره', $e->getMessage());
        }

        $this->assertNull($return->refresh()->custodyTransfer()->first());
        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);
    }

    public function test_another_customer_cannot_confirm_someone_elses_return(): void
    {
        [, $application, , , , $return] = $this->returned('09170002010', '09170002011', 'RH-005');

        $this->actingAs($this->customer('09170002012'))
            ->post(route('rental.applications.handover.acknowledge', $application), ['operation' => $return->id])
            ->assertForbidden();

        $this->assertSame(CustodyTransferState::Transferred, $return->custodyTransfer()->firstOrFail()->state);
    }

    public function test_the_customer_sees_the_factual_return_status_and_no_inspection_or_owner_detail(): void
    {
        [$customer, $application, , $device, , $return] = $this->returned('09170002013', '09170002014', 'RH-006');

        $this->inspections->record($return, $this->admin, 'یافته داخلی: خط‌وخش روی بدنه');

        $response = $this->actingAs($customer)
            ->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertSee('دستگاه به شما تحویل داده شد.')
            ->assertSee('دستگاه توسط گیم‌پک از شما دریافت شد.');

        $response->assertDontSee('یافته داخلی');
        // The door-check note written on the delivery handover is staff-only.
        $response->assertDontSee('سالم تحویل شد');
        $response->assertDontSee($device->serial_number);
        $response->assertDontSee($device->owner->user->full_name);
    }

    // ── 2. Inspection domain ─────────────────────────────────────────────

    public function test_a_return_inspection_derives_every_reference_from_its_operation(): void
    {
        [, $application, $reservation, $device, , $return] = $this->returned('09170002015', '09170002016', 'IN-001');

        $inspection = $this->inspections->record($return, $this->admin, '  کنترلر سالم، بدنه بدون آسیب  ');

        $this->assertSame($return->id, $inspection->rental_operation_id);
        $this->assertSame($return->custodyTransfer()->firstOrFail()->id, $inspection->device_custody_transfer_id);
        $this->assertSame($reservation->id, $inspection->rental_reservation_id);
        $this->assertSame($application->id, $inspection->rental_application_id);
        $this->assertSame($device->id, $inspection->device_id);
        $this->assertSame(RentalInspectionStage::CustomerReturn, $inspection->stage);
        $this->assertSame($this->admin->id, $inspection->inspected_by_user_id);
        $this->assertSame('کنترلر سالم، بدنه بدون آسیب', $inspection->findings);
        $this->assertNotNull($inspection->inspected_at);
    }

    public function test_a_delivery_inspection_is_recorded_at_the_delivery_stage(): void
    {
        [, , , , $delivery] = $this->delivered('09170002017', '09170002018', 'IN-002');

        $inspection = $this->inspections->record($delivery, $this->admin, 'بررسی در محل مشتری انجام شد');

        $this->assertSame(RentalInspectionStage::Delivery, $inspection->stage);
    }

    public function test_several_inspections_may_be_appended_to_one_return(): void
    {
        [, , , , , $return] = $this->returned('09170002019', '09170002020', 'IN-003');

        $this->inspections->record($return, $this->admin, 'بررسی اولیه در محل');
        $this->inspections->record($return, $this->admin, 'بررسی کارشناس در دفتر');

        $this->assertSame(2, RentalInspection::where('rental_operation_id', $return->id)->count());
    }

    public function test_an_inspection_is_refused_before_the_handover_is_recorded(): void
    {
        [, , $reservation] = $this->delivered('09170002021', '09170002022', 'IN-004');

        $return = $this->operations->openReturnForReservation($reservation, $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->inspections->record($return->refresh(), $this->admin, 'پیش از دریافت');
    }

    public function test_the_owner_legs_have_no_inspection_stage(): void
    {
        [, , $reservation, , $ownerReturn] = $this->returnedToOwner('09170002023', '09170002024', 'IN-005');

        foreach ([$this->pickupFor($reservation), $ownerReturn] as $operation) {
            try {
                $this->inspections->record($operation, $this->admin, 'یادداشت');
                $this->fail('No inspection is confirmed for an owner leg.');
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->assertSame(0, RentalInspection::count());
    }

    public function test_an_inspection_is_refused_when_the_handover_names_another_device(): void
    {
        [, $application, , $device, , $return] = $this->returned('09170002025', '09170002026', 'IN-006');

        $other = $this->ownerDevice($application->product, '09170002027', 'IN-006-B');

        DB::table('device_custody_transfers')->where('rental_operation_id', $return->id)
            ->update(['device_id' => $other->id]);

        $this->expectException(\RuntimeException::class);

        try {
            $this->inspections->record($return, $this->admin, 'دستگاه اشتباه');
        } finally {
            $this->assertSame(0, RentalInspection::count());
        }
    }

    public function test_an_empty_inspection_is_refused(): void
    {
        [, , , , , $return] = $this->returned('09170002028', '09170002029', 'IN-007');

        $this->expectException(\RuntimeException::class);
        $this->inspections->record($return, $this->admin, "   \n ");
    }

    public function test_an_inspection_can_be_neither_edited_nor_deleted(): void
    {
        [, , , , , $return] = $this->returned('09170002030', '09170002031', 'IN-008');
        $inspection = $this->inspections->record($return, $this->admin, 'متن اصلی');

        try {
            $inspection->findings = 'متن تغییر یافته';
            $inspection->save();
            $this->fail('An inspection must not be editable.');
        } catch (\LogicException) {
            // expected
        }

        try {
            $inspection->delete();
            $this->fail('An inspection must not be deletable.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame('متن اصلی', RentalInspection::findOrFail($inspection->id)->findings);
    }

    public function test_an_inspection_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        RentalInspection::create(['findings' => 'x', 'device_id' => 1]);
    }

    public function test_an_inspection_is_audited_without_the_raw_serial(): void
    {
        [, , , $device, , $return] = $this->returned('09170002032', '09170002033', 'IN-009');

        $inspection = $this->inspections->record($return, $this->admin, 'ثبت برای ممیزی');

        $event = AuditEvent::forAction('inspection.recorded')->firstOrFail();
        $this->assertSame($inspection->id, (int) $event->resource_id);
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertStringNotContainsString($device->serial_number, json_encode($event->context, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_inspection_route_ignores_posted_references(): void
    {
        [, $application, , $device, , $return] = $this->returned('09170002034', '09170002035', 'IN-010');
        $other = $this->ownerDevice($application->product, '09170002036', 'IN-010-B');

        $this->actingAs($this->admin)
            ->post(route('admin.operations.inspections.store', $return), [
                'findings' => 'از طریق فرم',
                'device_id' => $other->id,
                'rental_application_id' => 999999,
                'stage' => 'delivery',
                'inspected_by_user_id' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $inspection = RentalInspection::firstOrFail();
        $this->assertSame($device->id, $inspection->device_id);
        $this->assertSame($application->id, $inspection->rental_application_id);
        $this->assertSame(RentalInspectionStage::CustomerReturn, $inspection->stage);
        $this->assertSame($this->admin->id, $inspection->inspected_by_user_id);
    }

    public function test_the_inspection_route_requires_findings(): void
    {
        [, , , , , $return] = $this->returned('09170002037', '09170002038', 'IN-011');

        $this->actingAs($this->admin)
            ->post(route('admin.operations.inspections.store', $return), ['findings' => ''])
            ->assertSessionHasErrors('findings');

        $this->assertSame(0, RentalInspection::count());
    }

    public function test_the_inspection_route_is_closed_to_customers_and_to_staff_without_manage_operations(): void
    {
        [$customer, , , , , $return] = $this->returned('09170002039', '09170002040', 'IN-012');

        $this->actingAs($customer->fresh())
            ->post(route('admin.operations.inspections.store', $return), ['findings' => 'x'])
            ->assertRedirect(route('admin.login'));

        $this->actingAs($this->staff('09170002041', 'support.inspect@test.local'))
            ->post(route('admin.operations.inspections.store', $return), ['findings' => 'x'])
            ->assertForbidden();

        $this->assertSame(0, RentalInspection::count());
    }

    public function test_the_admin_screen_shows_inspections_and_the_defect_window(): void
    {
        [, , , , , $return] = $this->returned('09170002042', '09170002043', 'IN-013');
        $this->inspections->record($return, $this->admin, 'یافته قابل مشاهده برای کارشناس');

        $this->actingAs($this->admin)
            ->get(route('admin.operations.show', $return))
            ->assertOk()
            ->assertSee('بازرسی دستگاه')
            ->assertSee('یافته قابل مشاهده برای کارشناس')
            ->assertSee('پایان مهلت اعلام ایراد توسط مالک');
    }

    // ── 3. The owner's two-hour window (C-38) ────────────────────────────

    private function returnTransferReceivedAt(string $at, string $mobile, string $ownerMobile, string $serial): DeviceCustodyTransfer
    {
        [, , $reservation] = $this->delivered($mobile, $ownerMobile, $serial);

        $return = $this->operations->openReturnForReservation($reservation, $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);

        Carbon::setTestNow(Carbon::parse($at));
        $transfer = $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);
        Carbon::setTestNow();

        return $transfer->fresh();
    }

    public function test_the_window_is_open_just_inside_two_hours(): void
    {
        $transfer = $this->returnTransferReceivedAt('2026-10-01 10:00:00', '09170002044', '09170002045', 'WIN-101');

        $this->assertTrue($transfer->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-01 10:00:00')));
        $this->assertTrue($transfer->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-01 11:59:59')));
    }

    public function test_the_window_is_closed_exactly_at_the_two_hour_boundary(): void
    {
        $transfer = $this->returnTransferReceivedAt('2026-10-01 10:00:00', '09170002046', '09170002047', 'WIN-102');

        $this->assertSame('2026-10-01 12:00:00', $transfer->ownerDefectReportDeadline()->toDateTimeString());
        $this->assertFalse($transfer->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-01 12:00:00')));
    }

    public function test_the_window_is_closed_after_two_hours_and_before_receipt(): void
    {
        $transfer = $this->returnTransferReceivedAt('2026-10-01 10:00:00', '09170002048', '09170002049', 'WIN-103');

        $this->assertFalse($transfer->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-01 12:00:01')));
        $this->assertFalse($transfer->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-02 10:00:00')));
        // Nothing can be reported about a receipt that had not happened yet.
        $this->assertFalse($transfer->isWithinOwnerDefectReportWindow(Carbon::parse('2026-10-01 09:59:59')));
    }

    public function test_there_is_no_window_before_possession_has_moved(): void
    {
        [, , $reservation] = $this->delivered('09170002050', '09170002051', 'WIN-104');

        $return = $this->operations->openReturnForReservation($reservation, $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $requested = $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);

        $this->assertNull($requested->fresh()->ownerDefectReportDeadline());
        $this->assertFalse($requested->fresh()->isWithinOwnerDefectReportWindow());
    }

    public function test_only_the_customer_return_starts_the_window(): void
    {
        [, , $reservation, , $ownerReturn, $ownerTransfer] = $this->returnedToOwner('09170002052', '09170002053', 'WIN-105');

        $pickupTransfer = $this->pickupFor($reservation)->custodyTransfer()->firstOrFail();

        // The owner pickup also ends in GamePek custody, but it is the owner
        // handing over their own console -- not the receipt C-38 is about.
        $this->assertTrue($pickupTransfer->transfer_type->endsInGamePekCustody());
        $this->assertNull($pickupTransfer->ownerDefectReportDeadline());
        $this->assertNull($ownerTransfer->fresh()->ownerDefectReportDeadline());
    }

    public function test_the_window_is_the_same_instant_in_any_timezone_and_survives_a_database_round_trip(): void
    {
        $transfer = $this->returnTransferReceivedAt('2026-10-01 10:00:00', '09170002054', '09170002055', 'WIN-106');

        $received = $transfer->transferred_at->copy()->utc();
        $deadline = $transfer->ownerDefectReportDeadline();

        $this->assertSame(
            $received->copy()->addHours(2)->getTimestamp(),
            $deadline->getTimestamp(),
        );

        // The same instants, expressed in unrelated zones, get the same answer.
        $this->assertTrue($transfer->isWithinOwnerDefectReportWindow(
            $received->copy()->addMinutes(119)->setTimezone('America/New_York'),
        ));
        $this->assertFalse($transfer->isWithinOwnerDefectReportWindow(
            $received->copy()->addHours(2)->setTimezone('Asia/Tokyo'),
        ));

        $this->assertSame($deadline->getTimestamp(), DeviceCustodyTransfer::findOrFail($transfer->id)->ownerDefectReportDeadline()->getTimestamp());
    }

    // ── 4. GamePek -> owner ──────────────────────────────────────────────

    public function test_a_returned_device_goes_back_to_its_owner_without_touching_the_application_state(): void
    {
        [, $application, , $device, $ownerReturn, $transfer] = $this->returnedToOwner('09170002056', '09170002057', 'OR-001');

        $this->assertSame(RentalOperationType::OwnerReturn, $ownerReturn->type);
        $this->assertSame(RentalOperationState::Completed, $ownerReturn->state);
        $this->assertSame(CustodyTransferType::GamePekToOwner, $transfer->transfer_type);
        $this->assertSame(CustodyActor::GamePek, $transfer->from_actor_type);
        $this->assertSame(CustodyActor::Owner, $transfer->to_actor_type);
        $this->assertNull($transfer->from_owner_id);
        $this->assertSame($device->owner_id, $transfer->to_owner_id);
        $this->assertStringStartsWith('CUS-', $transfer->reference_number);

        $this->assertSame(CustodyActor::Owner, $device->fresh()->currentCustody());
        // Custody moved; ownership did not.
        $this->assertSame(DeviceOwnership::Owner, $device->fresh()->ownership);
        // Closure is undecided: the rental stays Returned.
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }

    public function test_an_owner_return_cannot_be_opened_while_the_customer_has_the_device(): void
    {
        [, $application, $reservation] = $this->delivered('09170002058', '09170002059', 'OR-002');

        try {
            $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
            $this->fail('No owner return while the rental is Active.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalOperation::where('type', RentalOperationType::OwnerReturn->value)->count());
        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);
    }

    public function test_the_database_refuses_a_direct_customer_to_owner_handover(): void
    {
        [, , , $device] = $this->delivered('09170002060', '09170002061', 'OR-003');

        $rows = [
            // A leg that does not exist.
            ['transfer_type' => 'customer_to_owner', 'from_actor_type' => 'customer', 'to_actor_type' => 'owner'],
            // A real leg carrying the wrong source.
            ['transfer_type' => 'gamepek_to_owner', 'from_actor_type' => 'customer', 'to_actor_type' => 'owner'],
        ];

        foreach ($rows as $i => $row) {
            try {
                DB::table('device_custody_transfers')->insert($row + [
                    'reference_number' => 'CUS-FORGED-'.$i,
                    'device_id' => $device->id,
                    'to_owner_id' => $device->owner_id,
                    'state' => 'transferred',
                    'transferred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail('The custody actor-pair constraint must refuse '.$row['transfer_type'].' from customer.');
            } catch (QueryException) {
                // expected
            }
        }

        $this->assertSame(CustodyActor::Customer, $device->fresh()->currentCustody());
    }

    public function test_an_owner_return_is_refused_when_the_task_names_another_owner(): void
    {
        [, $application, $reservation] = $this->returned('09170002062', '09170002063', 'OR-004');

        $ownerReturn = $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);

        $stranger = $this->ownerDevice($application->product, '09170002064', 'OR-004-B')->owner;
        DB::table('rental_operations')->where('id', $ownerReturn->id)->update(['owner_id' => $stranger->id]);

        $this->expectException(\RuntimeException::class);

        try {
            $this->custody->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        } finally {
            $this->assertNull($ownerReturn->refresh()->custodyTransfer()->first());
        }
    }

    public function test_a_duplicate_owner_return_records_one_handover(): void
    {
        [, , $reservation, , $ownerReturn] = $this->returnedToOwner('09170002065', '09170002066', 'OR-005');

        $this->assertSame($ownerReturn->id, $this->operations->openOwnerReturnForReservation($reservation, $this->admin)->id);

        try {
            $this->custody->recordReturnToOwner($ownerReturn->refresh(), $this->admin);
            $this->fail('A second owner-return record must be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, DeviceCustodyTransfer::where('rental_operation_id', $ownerReturn->id)->count());
        $this->assertSame(1, RentalOperation::where('type', RentalOperationType::OwnerReturn->value)->count());
    }

    public function test_a_gamepek_owned_device_has_no_owner_return(): void
    {
        [, $application, $reservation, $device, , $return] = $this->returned('09170002067', null, 'OR-006');

        // The GamePek-owned regression: pickup not required, delivery and
        // return work, the device ends up back in GamePek custody.
        $this->assertSame(RentalOperationState::NotRequired, $this->pickupFor($reservation)->state);
        $this->assertSame(RentalApplicationState::Returned, $application->state);
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());

        $this->inspections->record($return, $this->admin, 'دستگاه گیم‌پک سالم دریافت شد');

        $this->expectException(\RuntimeException::class);
        $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
    }

    public function test_the_owner_can_confirm_the_return_to_them_without_moving_custody(): void
    {
        [, , , $device, $ownerReturn] = $this->returnedToOwner('09170002068', '09170002069', 'OR-007');

        $this->actingAs($device->owner->user)
            ->post(route('owner.operations.acknowledge', $ownerReturn))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(CustodyTransferState::Acknowledged, $ownerReturn->custodyTransfer()->firstOrFail()->state);
        $this->assertSame(CustodyActor::Owner, $device->fresh()->currentCustody());
    }

    public function test_another_owner_cannot_confirm_the_return(): void
    {
        [, $application, , , $ownerReturn] = $this->returnedToOwner('09170002070', '09170002071', 'OR-008');

        $stranger = $this->ownerDevice($application->product, '09170002072', 'OR-008-B')->owner->user;

        $this->actingAs($stranger)
            ->post(route('owner.operations.acknowledge', $ownerReturn))
            ->assertForbidden();

        // And the service refuses it on its own, whatever the route does.
        try {
            $this->custody->acknowledgeByOwner($ownerReturn->custodyTransfer()->firstOrFail(), $stranger);
            $this->fail('A stranger owner must not confirm the return.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(CustodyTransferState::Transferred, $ownerReturn->custodyTransfer()->firstOrFail()->state);
    }

    public function test_the_customer_cannot_confirm_the_owner_leg(): void
    {
        [$customer, $application, , , $ownerReturn] = $this->returnedToOwner('09170002073', '09170002074', 'OR-009');

        // A real operation of the customer's own rental -- but the customer is
        // not a party to the GamePek -> owner handover.
        $this->actingAs($customer)
            ->postJson(route('rental.applications.handover.acknowledge', $application), ['operation' => $ownerReturn->id])
            ->assertStatus(422);

        $this->assertSame(CustodyTransferState::Transferred, $ownerReturn->custodyTransfer()->firstOrFail()->state);
    }

    public function test_the_owner_return_runs_end_to_end_through_the_admin_routes(): void
    {
        [, $application, , $device] = $this->returned('09170002075', '09170002076', 'OR-010');

        $this->actingAs($this->admin)
            ->post(route('admin.rental-applications.owner-return.open', $application))
            ->assertRedirect();

        $ownerReturn = RentalOperation::where('type', RentalOperationType::OwnerReturn->value)->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.operations.show', $ownerReturn))
            ->assertOk()->assertSee('بازگرداندن دستگاه به مالک');

        $this->actingAs($this->admin)->post(route('admin.operations.start', $ownerReturn))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.operations.custody', $ownerReturn), [
            'notes' => 'تحویل مالک شد',
            // Ignored: the destination is derived from the leg.
            'to_actor_type' => 'customer',
        ])->assertSessionHas('success');

        $this->assertSame(RentalOperationState::Completed, $ownerReturn->refresh()->state);
        $this->assertSame(CustodyActor::Owner, $device->fresh()->currentCustody());
    }

    public function test_the_owner_return_route_is_closed_to_customers_and_to_staff_without_manage_operations(): void
    {
        [$customer, $application] = $this->returned('09170002077', '09170002078', 'OR-011');

        $this->actingAs($customer->fresh())
            ->post(route('admin.rental-applications.owner-return.open', $application))
            ->assertRedirect(route('admin.login'));

        $this->actingAs($this->staff('09170002079', 'support.owner-return@test.local'))
            ->post(route('admin.rental-applications.owner-return.open', $application))
            ->assertForbidden();

        $this->assertSame(0, RentalOperation::where('type', RentalOperationType::OwnerReturn->value)->count());
    }

    public function test_the_customer_page_never_exposes_the_owner_after_the_owner_return(): void
    {
        [$customer, $application, , $device] = $this->returnedToOwner('09170002080', '09170002081', 'OR-012');

        $response = $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();

        $response->assertDontSee($device->owner->user->full_name);
        $response->assertDontSee($device->owner->user->mobile);
        $response->assertDontSee('بازگرداندن دستگاه به مالک');
    }

    // ── 5. The circular lifecycle: custody-aware pickups ─────────────────

    public function test_a_later_rental_of_a_device_gamepek_still_holds_needs_no_pickup(): void
    {
        [, , $first, $device] = $this->returned('09170002082', '09170002083', 'CY-001');

        $second = $this->laterRentalOfSameDevice($device, $first, '09170002084');
        $pickup = $this->operations->attachDevice($this->pickupFor($second), $device, $this->admin);

        // GamePek already has it: asking the owner would invent a handover.
        $this->assertSame(RentalOperationState::NotRequired, $pickup->state);
        $this->assertNull($pickup->custodyTransfer()->first());
        $this->assertSame(DeviceOwnership::Owner, $device->fresh()->ownership);
    }

    public function test_a_later_rental_of_a_device_returned_to_its_owner_needs_a_real_pickup(): void
    {
        [, , $first, $device] = $this->returnedToOwner('09170002085', '09170002086', 'CY-002');

        $second = $this->laterRentalOfSameDevice($device, $first, '09170002087');
        $pickup = $this->operations->attachDevice($this->pickupFor($second), $device, $this->admin);

        $this->assertSame(RentalOperationState::Scheduled, $pickup->state);

        $this->operations->start($pickup->refresh(), $this->admin);
        $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
        $this->custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);

        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());
    }

    public function test_an_owner_pickup_is_refused_while_a_customer_holds_the_device(): void
    {
        [, , $first, $device] = $this->delivered('09170002088', '09170002089', 'CY-003');

        $second = $this->laterRentalOfSameDevice($device, $first, '09170002090');
        $pickup = $this->operations->attachDevice($this->pickupFor($second), $device, $this->admin);
        $this->operations->start($pickup->refresh(), $this->admin);

        $this->expectException(\RuntimeException::class);

        try {
            $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
        } finally {
            $this->assertSame(CustodyActor::Customer, $device->fresh()->currentCustody());
        }
    }

    // ── 6. Reconciliation ────────────────────────────────────────────────

    public function test_a_full_four_leg_lifecycle_with_inspections_reconciles_cleanly(): void
    {
        [, , , , , , $return] = $this->returnedToOwner('09170002091', '09170002092', 'RC-001');

        $this->inspections->record($return, $this->admin, 'بررسی بازگشت');

        // Every earlier leg was superseded by a later one; none is a finding.
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_the_reconciler_detects_an_owner_return_whose_device_is_not_with_the_owner(): void
    {
        [, , , , $ownerReturn] = $this->returnedToOwner('09170002093', '09170002094', 'RC-002');

        DB::table('device_custody_transfers')->where('rental_operation_id', $ownerReturn->id)
            ->update(['state' => CustodyTransferState::Requested->value, 'transferred_at' => null]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::COMPLETED_BUT_CUSTODY_NOT_OWNER, $codes);
        $this->assertContains(OperationCustodyReconciler::COMPLETED_WITHOUT_TRANSFER, $codes);
        // Read-only: it reported and repaired nothing.
        $this->assertSame(RentalOperationState::Completed, $ownerReturn->fresh()->state);
        $this->assertSame(CustodyTransferState::Requested, $ownerReturn->custodyTransfer()->firstOrFail()->state);
    }

    public function test_the_reconciler_detects_an_inspection_pointing_at_another_device(): void
    {
        [, $application, , , , $return] = $this->returned('09170002095', '09170002096', 'RC-003');
        $inspection = $this->inspections->record($return, $this->admin, 'بررسی');
        $other = $this->ownerDevice($application->product, '09170002097', 'RC-003-B');

        DB::table('rental_inspections')->where('id', $inspection->id)->update(['device_id' => $other->id]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::INSPECTION_REFERENCE_MISMATCH, $codes);
        $this->assertSame($other->id, RentalInspection::findOrFail($inspection->id)->device_id);
    }

    // ── 7. Lifecycle preparation and availability ────────────────────────

    public function test_a_returned_rental_is_stable_and_closure_still_fails_closed(): void
    {
        [, $application] = $this->returnedToOwner('09170002098', '09170002099', 'LC-001');

        $this->assertSame(RentalApplicationState::Returned, $application->state);

        // advance() derives nothing past Returned.
        $this->orchestrator->advance($application->refresh(), 'probe');
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);

        try {
            $this->orchestrator->transitionPostApproval($application->refresh(), RentalApplicationState::Closed, $this->admin);
            $this->fail('Closure must stay refused while its trigger is undecided.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertTrue(AuditEvent::forAction('rental_application.policy_undefined')->exists());
    }

    /**
     * Regression for a bug this batch's lifecycle test uncovered: advance()
     * re-derived the pre-approval ladder for post-approval rentals, so a
     * customer merely opening their page moved a live rental back to
     * AwaitingFinalApproval.
     */
    public function test_viewing_the_application_never_moves_a_live_or_returned_rental_backwards(): void
    {
        [$customer, $application] = $this->delivered('09170002102', '09170002103', 'LC-002');

        $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();
        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);

        $reservation = $application->reservation()->firstOrFail();
        $return = $this->operations->openReturnForReservation($reservation, $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();
        $this->actingAs($customer)->get(route('rental.applications.index'))->assertOk();
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);

        // No backwards transition was ever written to the ledger.
        $this->assertFalse(
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::AwaitingFinalApproval->value)
                ->where('id', '>', RentalApplicationTransition::where('rental_application_id', $application->id)
                    ->where('to_state', RentalApplicationState::Approved->value)->value('id'))
                ->exists(),
        );
    }

    public function test_no_operation_completion_can_produce_closure(): void
    {
        $source = file_get_contents(app_path('Services/Rental/RentalOperationService.php'));

        // The only lifecycle effects an operation has are Active and Returned.
        $this->assertStringNotContainsString('RentalApplicationState::Closed', $source);
        $this->assertNull(config('rental.lifecycle.closure_trigger'));
    }

    /**
     * SUPERSEDED premise: releasing the unused range after an early return
     * was undecided, so this pinned "the return changes no blocking".
     * Confirmed since: the device is free after its actual return. What still
     * holds: the reservation's state and contractual dates are untouched.
     */
    public function test_an_early_return_releases_the_unused_range_without_touching_the_contract(): void
    {
        [, , $reservation] = $this->returnedToOwner('09170002100', '09170002101', 'AV-001');
        $end = $reservation->end_date->toDateString();

        $this->assertSame('paid', $reservation->refresh()->state->value);
        $this->assertSame($end, $reservation->end_date->toDateString());
        $this->assertNotNull($reservation->returned_on);

        $this->assertFalse(RentalReservation::overlapping(
            $reservation->product_id,
            $reservation->start_date->toDateString(),
            $reservation->end_date->toDateString(),
        )->blocking()->whereKey($reservation->id)->exists());

        // ...and it blocks nothing outside its own dates.
        $this->assertFalse(RentalReservation::overlapping(
            $reservation->product_id,
            $reservation->end_date->copy()->addDay()->toDateString(),
            $reservation->end_date->copy()->addDays(5)->toDateString(),
        )->blocking()->whereKey($reservation->id)->exists());
    }
}
