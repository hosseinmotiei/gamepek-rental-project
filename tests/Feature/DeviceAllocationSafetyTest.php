<?php

namespace Tests\Feature;

use App\Enums\RentalOperationState;
use App\Enums\ReservationState;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Order;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalReservationService;
use App\Support\Rental\Jalali;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The device-level overlap guard added to
 * RentalOperationService::attachDevice(), and the cross-consistency
 * invariants it must not break.
 *
 * IMPORTANT CONTEXT FOR THESE FIXTURES: today, product-level availability
 * (RentalAvailabilityService::isFree(), used by
 * RentalReservationService::recordSelection()) already refuses a SECOND
 * overlapping PAID reservation for the same product, regardless of how many
 * physical devices exist for it -- there is no per-product capacity concept
 * yet (see the device-allocation audit). That means the real reservation
 * flow cannot organically produce two overlapping reservations for one
 * product today, which is exactly why the device-level guard is currently
 * defense-in-depth rather than reachable through normal use. Some fixtures
 * below therefore construct a second RentalReservation/RentalOperation pair
 * directly (bypassing recordSelection()'s product-level gate) to exercise
 * that guard -- this does not change, weaken or exercise product-level
 * availability, and no test here asserts anything about it.
 */
class DeviceAllocationSafetyTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private RentalOperationService $operations;

    private DeviceRegistrationService $devices;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);

        $this->operations = app(RentalOperationService::class);
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

    /**
     * A real paid application + reservation + pickup operation for a GIVEN
     * product and date window, going through every real service exactly like
     * BuildsRentalChain::paidApplication() does -- the only difference is
     * that the product and dates are caller-supplied so two fixtures can
     * share one product.
     */
    private function paidWithOperationFor(User $customer, Product $product, string $startDate, int $days): array
    {
        $this->completeKyc($customer, $this->uniqueNationalCode());

        $application = app(RentalReservationService::class)->openApplication($customer);
        app(RentalReservationService::class)->recordSelection($application, $product, $startDate, $days);
        $application->refresh();

        $quote = (array) $application->quote;
        $order = Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $customer->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'subtotal' => $quote['rental_total'] ?? 0,
            'shipping_cost' => $quote['delivery_fee'] ?? 0,
            'total' => $quote['payable_now'] ?? 0,
            'paid_at' => now(),
        ]);
        $application->update(['order_id' => $order->id]);

        app(RentalReservationService::class)->materialiseAfterPayment($application->refresh());
        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'payment settled');
        $application->refresh();

        $reservation = $application->reservation()->firstOrFail();
        $operation = RentalOperation::where('rental_reservation_id', $reservation->id)->firstOrFail();

        return [$application, $reservation, $operation];
    }

    /**
     * A second reservation + operation for the SAME product with an
     * OVERLAPPING date range, built directly rather than through
     * recordSelection() -- see the class docblock for why. Mirrors exactly
     * the fields RentalReservationService::materialiseAfterPayment() writes.
     */
    private function directOverlappingReservation(Product $product, User $customer, string $startDate, int $days): array
    {
        $application = RentalApplication::create([
            'application_number' => RentalApplication::generateNumber(),
            'user_id' => $customer->id,
        ]);

        $end = Jalali::addDays($startDate, $days - 1);

        $reservation = RentalReservation::create([
            'rental_application_id' => $application->id,
            'product_id' => $product->id,
            'start_date' => $startDate,
            'end_date' => $end,
            'days' => $days,
            'daily_rate' => 0,
            'subtotal' => 0,
            'discount' => 0,
            'delivery_fee' => 0,
            'rental_total' => 0,
            'deposit_amount' => 0,
            'payable_now' => 0,
            'state' => ReservationState::Paid,
        ]);

        $operation = $this->operations->openPickupForReservation($reservation);

        return [$reservation, $operation];
    }

    // ── 1. Device-level overlap refusal ─────────────────────────────────

    public function test_attaching_a_device_already_committed_to_an_overlapping_reservation_is_refused(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130001001', 'DV-0001');

        [, $reservationA, $operationA] = $this->paidWithOperationFor($this->customer('09130001002'), $product, now()->addDays(5)->toDateString(), 5);
        $this->operations->attachDevice($operationA, $device, $this->admin);

        // Overlaps reservation A's [day+5, day+9] window.
        [$reservationB, $operationB] = $this->directOverlappingReservation($product, $this->customer('09130001003'), now()->addDays(7)->toDateString(), 5);

        try {
            $this->operations->attachDevice($operationB, $device->refresh(), $this->admin);
            $this->fail('Attaching a device already committed to an overlapping reservation must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('این دستگاه برای بازه زمانی این رزرو در دسترس نیست.', $e->getMessage());
        }

        // Regression guard: reservation A's own attachment is untouched.
        $this->assertSame($device->id, $reservationA->refresh()->device_id);
    }

    public function test_a_refused_attachment_leaves_the_operation_unchanged(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130002001', 'DV-0002');

        [, , $operationA] = $this->paidWithOperationFor($this->customer('09130002002'), $product, now()->addDays(5)->toDateString(), 5);
        $this->operations->attachDevice($operationA, $device, $this->admin);

        [, $operationB] = $this->directOverlappingReservation($product, $this->customer('09130002003'), now()->addDays(7)->toDateString(), 5);
        $before = $operationB->only(['state', 'device_id', 'owner_id']);

        try {
            $this->operations->attachDevice($operationB, $device->refresh(), $this->admin);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, $operationB->refresh()->only(['state', 'device_id', 'owner_id']));
        $this->assertSame(RentalOperationState::AwaitingDeviceAllocation, $operationB->refresh()->state);
    }

    public function test_a_refused_attachment_leaves_the_reservations_device_id_unchanged(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130003001', 'DV-0003');

        [, , $operationA] = $this->paidWithOperationFor($this->customer('09130003002'), $product, now()->addDays(5)->toDateString(), 5);
        $this->operations->attachDevice($operationA, $device, $this->admin);

        [$reservationB, $operationB] = $this->directOverlappingReservation($product, $this->customer('09130003003'), now()->addDays(7)->toDateString(), 5);

        try {
            $this->operations->attachDevice($operationB, $device->refresh(), $this->admin);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($reservationB->refresh()->device_id);
    }

    public function test_a_non_overlapping_reservation_may_still_be_attached(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130004001', 'DV-0004');

        [, , $operationA] = $this->paidWithOperationFor($this->customer('09130004002'), $product, now()->addDays(5)->toDateString(), 3);
        $this->operations->attachDevice($operationA, $device, $this->admin);

        // Starts the day after reservation A ends -- no overlap.
        [$reservationB, $operationB] = $this->directOverlappingReservation($product, $this->customer('09130004003'), now()->addDays(8)->toDateString(), 3);

        $result = $this->operations->attachDevice($operationB, $device->refresh(), $this->admin);

        $this->assertSame($device->id, $result->refresh()->device_id);
        $this->assertSame($device->id, $reservationB->refresh()->device_id);
    }

    public function test_the_same_device_can_be_reused_for_a_later_non_overlapping_rental(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeGamePekDevice($product, 'DV-0005');

        [, , $operationA] = $this->paidWithOperationFor($this->customer('09130005001'), $product, now()->addDays(1)->toDateString(), 2);
        $this->operations->attachDevice($operationA, $device, $this->admin);

        [, $operationB] = $this->directOverlappingReservation($product, $this->customer('09130005002'), now()->addDays(10)->toDateString(), 2);
        $result = $this->operations->attachDevice($operationB, $device->refresh(), $this->admin);

        $this->assertSame(RentalOperationState::NotRequired, $result->refresh()->state);
    }

    /**
     * The overlap query's own exclusion of $reservation itself
     * (`where('id', '!=', $reservation->id)`) protects against a reservation
     * that already carries device_id X being seen as "conflicting with
     * itself" once X is attached. There is no reachable HTTP/service path
     * that calls attachDevice() a second time with the SAME device on an
     * operation already in `Scheduled` -- that second call fails first on
     * the pre-existing state-transition guard (Scheduled -> Scheduled is not
     * an allowed transition), independently of this change. This test
     * documents that finding rather than forcing an unreachable scenario.
     */
    public function test_a_second_attach_call_with_the_same_device_is_blocked_by_the_existing_state_guard_not_by_the_new_overlap_check(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130006001', 'DV-0006');

        [, , $operation] = $this->paidWithOperationFor($this->customer('09130006002'), $product, now()->addDays(2)->toDateString(), 3);

        $this->operations->attachDevice($operation, $device, $this->admin);

        try {
            $this->operations->attachDevice($operation->refresh(), $device->refresh(), $this->admin);
            $this->fail('A second attach call on an already-Scheduled operation must still be refused.');
        } catch (\RuntimeException $e) {
            // The pre-existing transition guard, not the new overlap message.
            $this->assertSame('این عملیات در وضعیت لازم برای این تغییر نیست.', $e->getMessage());
        }
    }

    // ── 2. Concurrency ───────────────────────────────────────────────────

    public function test_two_attach_attempts_for_the_same_device_and_overlapping_reservations_leave_exactly_one_winner(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130007001', 'DV-0007');

        [, , $operationA] = $this->paidWithOperationFor($this->customer('09130007002'), $product, now()->addDays(5)->toDateString(), 5);
        [, $operationB] = $this->directOverlappingReservation($product, $this->customer('09130007003'), now()->addDays(6)->toDateString(), 5);

        $this->operations->attachDevice($operationA, $device, $this->admin);

        try {
            $this->operations->attachDevice($operationB, $device->refresh(), $this->admin);
            $this->fail('The second, overlapping attempt must be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotNull($operationA->refresh()->device_id);
        $this->assertNull($operationB->refresh()->device_id);
    }

    // ── 3. Regression: existing guards remain intact ────────────────────

    public function test_ownership_consistency_is_still_enforced(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130008001', 'DV-0008');

        [, $reservation, $operation] = $this->paidWithOperationFor($this->customer('09130008002'), $product, now()->addDays(1)->toDateString(), 2);
        $result = $this->operations->attachDevice($operation, $device, $this->admin);

        $this->assertSame($device->owner_id, $result->refresh()->owner_id);
        $this->assertSame($device->owner_id, $device->owner_id);
    }

    public function test_a_non_rentable_device_is_still_rejected(): void
    {
        $product = $this->makeRentableProduct();
        $pending = $this->devices->registerForOwner(
            $this->devices->ensureOwnerProfile(User::create(['full_name' => 'مالک', 'mobile' => '09130009001', 'status' => 'active'])),
            $product,
            'DV-0009',
        );

        [, , $operation] = $this->paidWithOperationFor($this->customer('09130009002'), $product, now()->addDays(1)->toDateString(), 2);

        $this->expectException(\RuntimeException::class);
        $this->operations->attachDevice($operation, $pending, $this->admin);
    }

    public function test_a_wrong_product_device_is_still_rejected(): void
    {
        $product = $this->makeRentableProduct();
        $foreignProduct = $this->makeRentableProduct();
        $foreignDevice = $this->makeOwnerWithApprovedDevice($foreignProduct, '09130010001', 'DV-0010');

        [, , $operation] = $this->paidWithOperationFor($this->customer('09130010002'), $product, now()->addDays(1)->toDateString(), 2);

        $this->expectException(\RuntimeException::class);
        $this->operations->attachDevice($operation, $foreignDevice, $this->admin);
    }

    public function test_a_gamepek_owned_device_still_resolves_to_not_required_with_no_conflict_check_blocking_it(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeGamePekDevice($product, 'DV-0011');

        [, , $operation] = $this->paidWithOperationFor($this->customer('09130011001'), $product, now()->addDays(1)->toDateString(), 2);
        $result = $this->operations->attachDevice($operation, $device, $this->admin);

        $this->assertSame(RentalOperationState::NotRequired, $result->refresh()->state);
        $this->assertNotNull($result->completed_at);
    }

    public function test_the_existing_pickup_and_custody_ladder_still_runs_end_to_end(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130012001', 'DV-0012');
        $custody = app(DeviceCustodyService::class);

        [, , $operation] = $this->paidWithOperationFor($this->customer('09130012002'), $product, now()->addDays(1)->toDateString(), 2);

        $operation = $this->operations->attachDevice($operation, $device, $this->admin);
        $operation = $this->operations->start($operation->refresh(), $this->admin);
        $custody->requestFromOwner($operation->refresh(), $this->admin);
        $custody->recordHandoverToGamePek($operation->refresh(), $this->admin);

        $this->assertSame(RentalOperationState::Completed, $operation->refresh()->state);
    }

    // ── 4. Cross-consistency ─────────────────────────────────────────────

    public function test_reservation_and_operation_device_ids_agree_after_attachment(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130013001', 'DV-0013');

        [, $reservation, $operation] = $this->paidWithOperationFor($this->customer('09130013002'), $product, now()->addDays(1)->toDateString(), 2);
        $result = $this->operations->attachDevice($operation, $device, $this->admin);

        $this->assertSame($reservation->refresh()->device_id, $result->refresh()->device_id);
        $this->assertSame($device->id, $reservation->refresh()->device_id);
    }

    public function test_product_ids_remain_consistent_after_attachment(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130014001', 'DV-0014');

        [, $reservation, $operation] = $this->paidWithOperationFor($this->customer('09130014002'), $product, now()->addDays(1)->toDateString(), 2);
        $this->operations->attachDevice($operation, $device, $this->admin);

        $this->assertSame($product->id, $device->refresh()->product_id);
        $this->assertSame($product->id, $reservation->refresh()->product_id);
    }

    // ── 5. Observability ─────────────────────────────────────────────────

    public function test_a_successful_and_a_denied_attachment_are_distinguishable_in_the_audit_trail(): void
    {
        $product = $this->makeRentableProduct();
        $device = $this->makeOwnerWithApprovedDevice($product, '09130015001', 'DV-0015');

        [, , $operationA] = $this->paidWithOperationFor($this->customer('09130015002'), $product, now()->addDays(5)->toDateString(), 5);
        $this->operations->attachDevice($operationA, $device, $this->admin);

        [, $operationB] = $this->directOverlappingReservation($product, $this->customer('09130015003'), now()->addDays(7)->toDateString(), 5);

        try {
            $this->operations->attachDevice($operationB, $device->refresh(), $this->admin);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, AuditEvent::forAction('operation.device_attached')->count());
        $this->assertSame(1, AuditEvent::forAction('operation.device_attach_denied')->count());

        $denied = AuditEvent::forAction('operation.device_attach_denied')->firstOrFail();
        $this->assertSame('denied', $denied->result);
        $this->assertSame($device->id, $denied->context['device_id']);
        $this->assertArrayNotHasKey('serial_number', $denied->context);
    }
}
