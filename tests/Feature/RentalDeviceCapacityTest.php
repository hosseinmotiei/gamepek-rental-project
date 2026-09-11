<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Exceptions\ReservationConflictException;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\Order;
use App\Models\Product;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\User;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalReservationService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * CONFIRMED: capacity is physical devices; an early return frees the device
 * for its remaining days; an owner may not reclaim a device mid-rental.
 * UNDECIDED and therefore absent: waitlists, queueing, backorders, any
 * fallback when every device is busy -- the booking is simply refused.
 */
class RentalDeviceCapacityTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $admin;

    private RentalAvailabilityService $availability;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

    private DeviceRegistrationService $devices;

    private int $seq = 0;

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
        $this->availability = app(RentalAvailabilityService::class);
        $this->operations = app(RentalOperationService::class);
        $this->custody = app(DeviceCustodyService::class);
        $this->devices = app(DeviceRegistrationService::class);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function customer(): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => '0935'.str_pad((string) ++$this->seq, 7, '0', STR_PAD_LEFT), 'status' => 'active']);
    }

    /** A product with $count approved GamePek devices, A, B, C… in id order. */
    private function productWithDevices(int $count): Product
    {
        $product = $this->makeRentableProduct();           // brings one device
        $this->giveProductPhysicalStock($product, $count - 1);

        return $product;
    }

    /** @return list<Device> */
    private function devicesOf(Product $product): array
    {
        return Device::where('product_id', $product->id)->orderBy('id')->get()->all();
    }

    /**
     * Pay for $product over [$start, $end] through the real post-payment path
     * (materialiseAfterPayment, under the product lock).
     */
    private function payFor(Product $product, string $start, string $end): RentalReservation
    {
        $customer = $this->customer();
        $application = $this->reservedApplication($customer, $this->uniqueNationalCode());

        DB::table('rental_applications')->where('id', $application->id)->update([
            'product_id' => $product->id,
            'selected_start_date' => $start,
            'selected_end_date' => $end,
        ]);

        $order = Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $customer->id, 'status' => 'processing', 'payment_status' => 'paid',
            'subtotal' => 1, 'shipping_cost' => 0, 'total' => 1, 'paid_at' => now(),
        ]);
        $application->update(['order_id' => $order->id]);

        return app(RentalReservationService::class)->materialiseAfterPayment($application->refresh());
    }

    private function attach(RentalReservation $reservation, Device $device): RentalOperation
    {
        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)->firstOrFail();

        return $this->operations->attachDevice($pickup, $device, $this->admin);
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    /**
     * A signed, approved rental of its own product (one GamePek device X),
     * delivered on its start day and returned the SAME day -- an early return.
     */
    private function rentedAndReturnedEarly(): array
    {
        $application = $this->signedApplication($this->customer(), '13579', $this->uniqueNationalCode(), '9876543210987654');
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $reservation = $application->reservation()->firstOrFail();
        [$deviceX] = $this->devicesOf($application->product);

        return [$application->refresh(), $reservation, $deviceX];
    }

    private function deliverAndReturn(RentalReservation $reservation, Device $device): void
    {
        $this->attach($reservation, $device);

        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);
    }

    // ── Multiple devices per product ─────────────────────────────────────

    public function test_three_devices_serve_three_overlapping_rentals_and_a_fourth_is_refused(): void
    {
        $product = $this->productWithDevices(3);
        [$a, $b, $c] = $this->devicesOf($product);

        $r1 = $this->payFor($product, $this->day(10), $this->day(12));
        $r2 = $this->payFor($product, $this->day(10), $this->day(12));
        $r3 = $this->payFor($product, $this->day(11), $this->day(13));

        $this->assertFalse($this->availability->isFree($product->id, $this->day(11), $this->day(11)));

        try {
            $this->payFor($product, $this->day(12), $this->day(14));
            $this->fail('A fourth overlapping booking exceeds three devices.');
        } catch (ReservationConflictException) {
            // expected
        }

        $this->assertSame(3, RentalReservation::where('product_id', $product->id)->count());

        foreach ([[$r1, $a], [$r2, $b], [$r3, $c]] as [$reservation, $device]) {
            $this->attach($reservation, $device);
        }

        $this->assertSame([$a->id, $b->id, $c->id], RentalReservation::where('product_id', $product->id)->orderBy('id')->pluck('device_id')->all());
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());

        // A range that no longer overlaps is free again; rendering the product
        // page changes nothing.
        $this->assertTrue($this->availability->isFree($product->id, $this->day(14), $this->day(16)));
        $this->get(route('products.show', $product->slug))->assertOk();
        $this->assertSame(3, RentalReservation::count());
    }

    public function test_one_physical_device_can_never_hold_two_overlapping_reservations(): void
    {
        $product = $this->productWithDevices(2);
        [$a] = $this->devicesOf($product);

        $r1 = $this->payFor($product, $this->day(10), $this->day(12));
        $r2 = $this->payFor($product, $this->day(11), $this->day(13));
        $this->attach($r1, $a);

        $this->expectException(\RuntimeException::class);

        try {
            $this->attach($r2, $a);
        } finally {
            $this->assertNull($r2->refresh()->device_id);
        }
    }

    public function test_a_manual_choice_that_would_strand_another_paid_booking_is_refused(): void
    {
        $product = $this->productWithDevices(2);
        [$a, $b] = $this->devicesOf($product);

        $r1 = $this->payFor($product, $this->day(10), $this->day(14));
        $r2 = $this->payFor($product, $this->day(15), $this->day(19));
        $r3 = $this->payFor($product, $this->day(12), $this->day(17));

        $this->attach($r1, $a);

        try {
            // r2 on B would leave r3 (12-17) overlapping A's r1 and B's r2.
            $this->attach($r2, $b);
            $this->fail('This choice strands r3.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('بدون دستگاه', $e->getMessage());
        }

        $this->attach($r2, $a);
        $this->attach($r3, $b);

        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_a_product_with_no_eligible_device_is_never_available(): void
    {
        $product = $this->makeRentableProduct();
        DB::table('devices')->where('product_id', $product->id)->update(['state' => 'disabled']);

        $this->assertFalse($this->availability->isFree($product->id, $this->day(5), $this->day(6)));

        $ranges = $this->availability->blockedRangesFor($product->id);
        $this->assertCount(1, $ranges);
        $this->assertSame(now()->toDateString(), $ranges[0]->from);

        $listed = $this->availability->constrainProductQuery(Product::query(), $this->day(5), $this->day(6))->pluck('id')->all();
        $this->assertNotContains($product->id, $listed);

        $this->expectException(ReservationConflictException::class);
        $this->payFor($product, $this->day(5), $this->day(6));
    }

    public function test_a_single_device_product_keeps_the_single_unit_behaviour(): void
    {
        $product = $this->productWithDevices(1);

        $this->payFor($product, $this->day(10), $this->day(12));

        $this->assertFalse($this->availability->isFree($product->id, $this->day(12), $this->day(13)));
        $this->assertTrue($this->availability->isFree($product->id, $this->day(13), $this->day(14)));

        $listed = $this->availability->constrainProductQuery(Product::query(), $this->day(11), $this->day(11))->pluck('id')->all();
        $this->assertNotContains($product->id, $listed);
    }

    public function test_an_unpaid_application_blocks_nothing(): void
    {
        $product = $this->productWithDevices(1);
        $application = $this->reservedApplication($this->customer(), $this->uniqueNationalCode());
        DB::table('rental_applications')->where('id', $application->id)->update([
            'product_id' => $product->id, 'selected_start_date' => $this->day(10), 'selected_end_date' => $this->day(12),
        ]);

        $this->assertSame(0, RentalReservation::where('product_id', $product->id)->count());
        $this->assertTrue($this->availability->isFree($product->id, $this->day(10), $this->day(12)));
    }

    // ── Early customer return ────────────────────────────────────────────

    public function test_an_early_return_frees_the_device_only_from_the_day_after_the_actual_return(): void
    {
        [$application, $reservation, $deviceX] = $this->rentedAndReturnedEarly();
        $product = $application->product;
        $end = $reservation->end_date->toDateString();
        $payable = $reservation->payable_now;

        // Nothing is released by a click: before the return is recorded the
        // whole contractual range still blocks.
        $this->travel(1)->days();
        $this->assertFalse($this->availability->isFree($product->id, $this->day(1), $end));

        $this->deliverAndReturn($reservation, $deviceX);
        $reservation->refresh();

        // The contract is untouched; the actual return is recorded beside it.
        $this->assertSame($end, $reservation->end_date->toDateString());
        $this->assertSame(now()->toDateString(), $reservation->returned_on->toDateString());
        $this->assertSame($payable, $reservation->payable_now);

        // The return day itself still blocks; the remaining days are free.
        $this->assertFalse($this->availability->isFree($product->id, now()->toDateString(), $this->day(1)));
        $this->assertTrue($this->availability->isFree($product->id, $this->day(1), $end));

        // A new customer can take the SAME physical device for those days.
        $next = $this->payFor($product, $this->day(1), $end);
        $this->attach($next, $deviceX);
        $this->assertSame($deviceX->id, $next->refresh()->device_id);

        // Only availability moved: no settlement, no closure.
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertSame(0, RentalSettlement::count());
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_an_early_return_adds_exactly_one_unit_while_the_other_devices_stay_blocked(): void
    {
        [$application, $reservation, $deviceX] = $this->rentedAndReturnedEarly();
        $product = $application->product;
        $this->giveProductPhysicalStock($product, 2);
        [, $deviceY, $deviceZ] = $this->devicesOf($product);
        $start = $reservation->start_date->toDateString();
        $end = $reservation->end_date->toDateString();

        $rb = $this->payFor($product, $start, $end);
        $rc = $this->payFor($product, $start, $end);
        $this->attach($rb, $deviceY);
        $this->attach($rc, $deviceZ);

        $this->travel(1)->days();
        $this->assertFalse($this->availability->isFree($product->id, $this->day(1), $end));

        $this->deliverAndReturn($reservation, $deviceX);

        $this->assertTrue($this->availability->isFree($product->id, $this->day(1), $end));
        $next = $this->payFor($product, $this->day(1), $end);

        foreach ([$deviceY, $deviceZ] as $busy) {
            try {
                $this->attach($next, $busy);
                $this->fail('Y and Z are still out with their own customers.');
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->attach($next, $deviceX);
        $this->assertFalse($this->availability->isFree($product->id, $this->day(1), $end));
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    // ── Owner mid-rental reclaim ─────────────────────────────────────────

    public function test_an_owner_cannot_take_back_a_device_during_its_rental(): void
    {
        $application = $this->signedApplication($this->customer(), '13579', $this->uniqueNationalCode(), '9876543210987654');
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $reservation = $application->reservation()->firstOrFail();

        $ownerUser = User::create(['full_name' => 'مالک', 'mobile' => '09350009999', 'status' => 'active']);
        $device = $this->devices->approve(
            $this->devices->registerForOwner($this->devices->ensureOwnerProfile($ownerUser), $application->product, 'OWN-R-1'),
            $this->admin,
        );

        $pickup = $this->attach($reservation, $device);
        $this->operations->start($pickup->refresh(), $this->admin);
        $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
        $this->custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);

        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);

        // Service level.
        try {
            $this->devices->disable($device->refresh(), $ownerUser);
            $this->fail('No mid-rental reclaim.');
        } catch (\RuntimeException) {
            // expected
        }

        // Route level: denied, and the control is not offered.
        $this->actingAs($ownerUser)->post(route('owner.devices.disable', $device))->assertForbidden();
        $this->actingAs($ownerUser)->get(route('owner.devices.show', $device))
            ->assertOk()
            ->assertDontSee(route('owner.devices.disable', $device), false);

        // The only legitimate way home is after the customer's return.
        try {
            $this->operations->openOwnerReturnForReservation($reservation->refresh(), $this->admin);
            $this->fail('No owner return while the rental is active.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('approved', $device->fresh()->state->value);
    }

    // ── Reconciliation ───────────────────────────────────────────────────

    public function test_the_reconciler_detects_double_booking_capacity_and_product_mismatch(): void
    {
        $product = $this->productWithDevices(3);
        [$a, $b, $c] = $this->devicesOf($product);
        $r1 = $this->payFor($product, $this->day(10), $this->day(12));
        $r2 = $this->payFor($product, $this->day(10), $this->day(12));
        $r3 = $this->payFor($product, $this->day(10), $this->day(12));
        $this->attach($r1, $a);
        $this->attach($r2, $b);
        $this->attach($r3, $c);

        $other = $this->makeRentableProduct();
        [$foreign] = $this->devicesOf($other);

        DB::table('rental_reservations')->where('id', $r2->id)->update(['device_id' => $a->id]);
        DB::table('rental_reservations')->where('id', $r3->id)->update(['device_id' => $foreign->id]);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::DEVICE_DOUBLE_BOOKED, $codes);
        $this->assertContains(OperationCustodyReconciler::RESERVATION_DEVICE_PRODUCT_MISMATCH, $codes);
    }

    public function test_the_reconciler_detects_bookings_that_no_longer_fit_the_devices(): void
    {
        $product = $this->productWithDevices(3);
        [, $b, $c] = $this->devicesOf($product);

        // Three unassigned overlapping bookings, admitted while three devices
        // were eligible...
        foreach (range(1, 3) as $i) {
            $this->payFor($product, $this->day(10), $this->day(12));
        }

        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());

        // ...then two devices leave the fleet out-of-band.
        DB::table('devices')->whereIn('id', [$b->id, $c->id])->update(['state' => 'disabled']);

        $this->assertContains(OperationCustodyReconciler::CAPACITY_EXCEEDED,
            app(OperationCustodyReconciler::class)->findings()->pluck('code')->all());
    }

    public function test_the_reconciler_detects_a_release_without_a_return_and_a_mid_rental_reclaim(): void
    {
        $product = $this->productWithDevices(1);
        [$device] = $this->devicesOf($product);
        $reservation = $this->payFor($product, $this->day(10), $this->day(12));
        $this->attach($reservation, $device);

        DB::table('rental_reservations')->where('id', $reservation->id)->update(['returned_on' => $this->day(10)]);
        DB::table('devices')->where('id', $device->id)->update(['state' => 'disabled']);

        $codes = app(OperationCustodyReconciler::class)->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::RETURN_RELEASE_MISMATCH, $codes);
        $this->assertContains(OperationCustodyReconciler::OWNER_RECLAIM_DURING_RENTAL, $codes);
    }
}
