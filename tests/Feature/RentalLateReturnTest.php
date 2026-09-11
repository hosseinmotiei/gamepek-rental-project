<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalSettlementService;
use App\Support\Rental\LateReturn;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * CONFIRMED late-return rule, both halves.
 *
 * AVAILABILITY: a device that has not come back by the contractual end date is
 * NOT released on that date. It stays unavailable until GamePek physically
 * receives it, and is free again from the day after the actual return. The
 * contractual dates themselves never change.
 *
 * MONEY: each late day costs the rental's own daily rate plus 15%. The figure
 * is a calculation only -- nothing is charged, nothing is settled, nothing is
 * added to rental_total, and the rental does not close because it ran late.
 */
class RentalLateReturnTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $admin;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

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
        $this->orchestrator = app(RentalChainOrchestrator::class);
    }

    // ── The calculation itself, in isolation ─────────────────────────────

    public function test_a_return_on_the_contract_end_date_is_not_late(): void
    {
        $late = LateReturn::of('2026-01-10', '2026-01-10', 500_000);

        $this->assertFalse($late->isLate);
        $this->assertSame(0, $late->lateDays);
        $this->assertSame(0, $late->total);
    }

    public function test_an_early_return_is_never_late(): void
    {
        $late = LateReturn::of('2026-01-10', '2026-01-07', 500_000);

        $this->assertFalse($late->isLate);
        $this->assertSame(0, $late->lateDays);
        $this->assertSame(0, $late->total);
    }

    public function test_there_is_no_late_charge_before_the_contract_end_date(): void
    {
        // Still running, three days before the end: nothing is owed yet.
        $late = LateReturn::of('2026-01-10', null, 500_000, today: '2026-01-07');

        $this->assertFalse($late->isLate);
        $this->assertSame(0, $late->total);
    }

    public function test_one_day_late_costs_the_daily_rate_plus_fifteen_percent(): void
    {
        $late = LateReturn::of('2026-01-10', '2026-01-11', 500_000);

        $this->assertTrue($late->isLate);
        $this->assertFalse($late->stillOut);
        $this->assertSame(1, $late->lateDays);
        $this->assertSame(500_000, $late->baseAmount);
        $this->assertSame(75_000, $late->surcharge);
        $this->assertSame(575_000, $late->total);
    }

    public function test_several_late_days_are_charged_per_day(): void
    {
        $late = LateReturn::of('2026-01-10', '2026-01-14', 500_000);

        $this->assertSame(4, $late->lateDays);
        $this->assertSame(2_000_000, $late->baseAmount);
        $this->assertSame(300_000, $late->surcharge);
        $this->assertSame(2_300_000, $late->total);
    }

    public function test_a_device_that_is_still_out_accrues_against_today_and_is_not_final(): void
    {
        $late = LateReturn::of('2026-01-10', null, 500_000, today: '2026-01-12');

        $this->assertTrue($late->isLate);
        $this->assertTrue($late->stillOut, 'a device that has not come back cannot have a final amount');
        $this->assertSame(2, $late->lateDays);
        $this->assertSame(1_150_000, $late->total);
    }

    public function test_the_calculation_is_deterministic_and_repeatable(): void
    {
        $first = LateReturn::of('2026-01-10', '2026-01-13', 437_000);
        $second = LateReturn::of('2026-01-10', '2026-01-13', 437_000);

        $this->assertEquals($first, $second);
        $this->assertSame(3, $first->lateDays);
        // 3 x 437,000 = 1,311,000; +15% = 196,650.
        $this->assertSame(1_311_000, $first->baseAmount);
        $this->assertSame(196_650, $first->surcharge);
        $this->assertSame(1_507_650, $first->total);
    }

    // ── Availability: the device stays out of the fleet ──────────────────

    public function test_a_late_device_is_not_released_on_the_contract_end_date(): void
    {
        [$application, $reservation] = $this->activeRental('09121000001', 'LATE-A');

        $end = $reservation->end_date->toDateString();
        $availability = app(RentalAvailabilityService::class);

        // Two days past the end, with the device still in the customer's hands.
        $this->travelTo($reservation->end_date->copy()->addDays(2));

        $this->assertSame(
            RentalReservation::OPEN_ENDED,
            $reservation->fresh()->blockedUntil()->toDateString(),
            'an overdue device has no release date until it is physically returned',
        );

        $dayAfterEnd = $reservation->end_date->copy()->addDay()->toDateString();
        $this->assertFalse($availability->isFree($reservation->product_id, $dayAfterEnd, $dayAfterEnd));

        // Even far in the future: the only device is not coming back on a date.
        $far = $reservation->end_date->copy()->addDays(60)->toDateString();
        $this->assertFalse($availability->isFree($reservation->product_id, $far, $far));

        // The contract itself is untouched.
        $this->assertSame($end, $reservation->fresh()->end_date->toDateString());
        $this->assertSame(RentalApplicationState::Active, $application->fresh()->state);
    }

    public function test_the_device_is_free_only_from_the_day_after_the_actual_late_return(): void
    {
        [$application, $reservation] = $this->activeRental('09121000002', 'LATE-B');

        $start = $reservation->start_date->toDateString();
        $end = $reservation->end_date->toDateString();

        $this->travelTo($reservation->end_date->copy()->addDays(2));
        $this->receiveBack($reservation);

        $reservation = $reservation->fresh();
        $availability = app(RentalAvailabilityService::class);
        $returnDay = $reservation->returned_on->toDateString();
        $nextDay = $reservation->returned_on->copy()->addDay()->toDateString();

        $this->assertSame($end, $reservation->end_date->toDateString(), 'the contract dates never move');
        $this->assertSame($start, $reservation->start_date->toDateString());
        $this->assertSame($returnDay, $reservation->blockedUntil()->toDateString());

        $this->assertFalse($availability->isFree($reservation->product_id, $returnDay, $returnDay),
            'the day the device came back was occupied by this rental');
        $this->assertTrue($availability->isFree($reservation->product_id, $nextDay, $nextDay),
            'the device is available again from the day after the actual return');

        // The days between the contractual end and the actual return stay
        // blocked -- the device really was out on them.
        $betweenDay = $reservation->end_date->copy()->addDay()->toDateString();
        $this->assertFalse($availability->isFree($reservation->product_id, $betweenDay, $betweenDay));

        $this->assertSame(RentalApplicationState::Returned, $application->fresh()->state);
    }

    // ── Money: calculated, never charged ─────────────────────────────────

    public function test_a_late_return_neither_closes_nor_settles_the_rental(): void
    {
        [$application, $reservation] = $this->activeRental('09121000003', 'LATE-C');

        $this->travelTo($reservation->end_date->copy()->addDays(3));
        $this->receiveBack($reservation);

        $application = $application->fresh();

        $this->assertSame(RentalApplicationState::Returned, $application->state,
            'a rental does not close because it ran late');
        $this->assertSame(0, RentalSettlement::count(), 'nothing is settled automatically');
        $this->assertSame(0, WalletTransaction::count(), 'a late fee moves no money');

        $late = $reservation->fresh()->lateReturn();
        $this->assertSame(3, $late->lateDays);
        $this->assertSame(1_725_000, $late->total);
        $this->assertFalse($late->stillOut);

        // Asked twice, the same answer: nothing accumulates a second charge.
        $this->assertEquals($late, $reservation->fresh()->lateReturn());
    }

    public function test_a_late_fee_is_never_added_to_the_rental_total_or_the_owner_split(): void
    {
        [$application, $reservation, $device] = $this->activeRental('09121000004', 'LATE-D', ownerMobile: '09129000004');

        $rentalTotalBefore = (int) $reservation->rental_total;

        $this->travelTo($reservation->end_date->copy()->addDays(2));
        $this->receiveBack($reservation);

        $return = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::CustomerReturn->value)->firstOrFail();
        app(RentalInspectionService::class)->record($return->refresh(), $this->admin, 'بررسی بازگشت');

        $settlement = app(RentalSettlementService::class)->calculate($application->fresh(), $this->admin);

        $this->assertSame($rentalTotalBefore, (int) $reservation->fresh()->rental_total,
            'the agreed rental price is not rewritten by a late return');
        $this->assertSame($rentalTotalBefore, $settlement->gross_amount,
            'the 35/65 split is still taken on the rental price alone');

        // The open question is recorded rather than answered.
        $this->assertTrue(
            AuditEvent::where('action', 'settlement.late_fee_undistributed')
                ->where('resource_id', $reservation->id)->exists(),
            'an undistributed late fee must leave an audit trail',
        );

        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()
            ->where('code', OperationCustodyReconciler::LATE_FEE_WITHOUT_POLICY)->values()->all());
    }

    public function test_the_reconciler_reports_a_device_released_while_still_with_the_customer(): void
    {
        [, $reservation] = $this->activeRental('09121000005', 'LATE-E');

        $this->travelTo($reservation->end_date->copy()->addDays(2));

        $reconciler = app(OperationCustodyReconciler::class);

        // Overdue but correctly still blocking: no finding.
        $this->assertSame([], $reconciler->findings()
            ->where('code', OperationCustodyReconciler::RELEASED_WHILE_STILL_OUT)->values()->all());

        // A hand-written release date while the device is demonstrably still
        // with the customer -- the exact state that would re-offer it.
        RentalReservation::whereKey($reservation->id)->update([
            'returned_on' => $reservation->end_date->toDateString(),
        ]);

        $codes = $reconciler->findings()->pluck('code')->all();

        $this->assertContains(OperationCustodyReconciler::RELEASED_WHILE_STILL_OUT, $codes);
    }

    public function test_the_reconciler_separates_a_legacy_return_from_a_wrong_release_date(): void
    {
        [, $reservation] = $this->activeRental('09121000006', 'LATE-F');

        $this->travelTo($reservation->end_date->copy()->addDay());
        $this->receiveBack($reservation);

        $reconciler = app(OperationCustodyReconciler::class);
        $this->assertSame([], $reconciler->findings()->all());

        // A row from before `returned_on` existed: the handover proves the
        // return, only the date was never written. Reported as an incomplete
        // record, and no date is invented for it.
        RentalReservation::whereKey($reservation->id)->update(['returned_on' => null]);

        $findings = $reconciler->findings();
        $this->assertContains(OperationCustodyReconciler::LEGACY_RETURN_WITHOUT_RELEASE_DATE, $findings->pluck('code')->all());
        $this->assertNotContains(OperationCustodyReconciler::RETURN_RELEASE_MISMATCH, $findings->pluck('code')->all());

        // And it must not block its device forever: the rental is Returned, so
        // the contractual end still bounds it.
        $this->assertSame(
            $reservation->end_date->toDateString(),
            $reservation->fresh()->blockedUntil()->toDateString(),
        );

        // A date that disagrees with the evidence is a different matter.
        RentalReservation::whereKey($reservation->id)->update([
            'returned_on' => $reservation->end_date->copy()->addDays(9)->toDateString(),
        ]);

        $this->assertContains(
            OperationCustodyReconciler::RETURN_RELEASE_MISMATCH,
            $reconciler->findings()->pluck('code')->all(),
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * A rental that is Active with the device in the customer's hands.
     *
     * @return array{0: RentalApplication, 1: RentalReservation, 2: Device}
     */
    private function activeRental(string $mobile, string $serial, ?string $ownerMobile = null): array
    {
        $customer = User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);

        $application = $this->signedApplication($customer);
        $this->orchestrator->approve($application, $this->admin, null);

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

        return [$application->refresh(), $reservation->refresh(), $device->refresh()];
    }

    private function receiveBack(RentalReservation $reservation): void
    {
        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);
    }

    /**
     * The device this rental runs on.
     *
     * For the GamePek case it is the ONE unit the fixture product already has,
     * deliberately: these tests are about a product whose only device is out,
     * so registering a second one would give the product spare capacity and
     * hide exactly what is being asserted.
     */
    private function device(Product $product, ?string $ownerMobile, string $serial): Device
    {
        $devices = app(DeviceRegistrationService::class);

        if ($ownerMobile === null) {
            return Device::where('product_id', $product->id)->rentable()->firstOrFail();
        }

        $device = $devices->registerForOwner(
            $devices->ensureOwnerProfile(User::create(['full_name' => 'مالک '.$serial, 'mobile' => $ownerMobile, 'status' => 'active'])),
            $product,
            $serial,
        );

        return $devices->approve($device, $this->admin)->refresh();
    }
}
