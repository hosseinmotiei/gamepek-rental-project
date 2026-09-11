<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\RentalDamagePayment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\GuaranteeNoteService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalDamageAssessmentService;
use App\Services\Rental\RentalDashboardMetrics;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The rental operations dashboard: real counts, real authorization, and a page
 * that changes nothing by being looked at.
 *
 * Every figure is asserted against a chain built through the real services, so
 * a metric that drifts from the domain fails here rather than misleading staff.
 */
class AdminRentalDashboardTest extends TestCase
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

    // ── Authorization ────────────────────────────────────────────────────

    public function test_a_guest_cannot_open_the_rental_dashboard(): void
    {
        $this->get(route('admin.rental-dashboard'))->assertRedirect();
    }

    public function test_a_customer_cannot_open_the_rental_dashboard(): void
    {
        $customer = User::create(['full_name' => 'مشتری', 'mobile' => '09151000001', 'status' => 'active'])->fresh();

        $this->actingAs($customer)->get(route('admin.rental-dashboard'))->assertRedirect();
    }

    public function test_an_owner_cannot_open_the_rental_dashboard(): void
    {
        $owner = User::create(['full_name' => 'مالک', 'mobile' => '09151000002', 'status' => 'active'])->fresh();
        app(DeviceRegistrationService::class)->ensureOwnerProfile($owner);

        $this->actingAs($owner)->get(route('admin.rental-dashboard'))->assertRedirect();
    }

    public function test_an_admin_without_the_rental_permission_is_refused(): void
    {
        // A real staff role that legitimately reaches the admin panel but has
        // no rental-chain permission: the product manager looks after physical
        // devices, not customers' rentals.
        $staff = User::create(['full_name' => 'کارمند', 'mobile' => '09151000003', 'status' => 'active']);
        $staff->assignRole('product_manager');

        $this->assertFalse($staff->fresh()->can('view_rental_applications'));

        $this->actingAs($staff->fresh())
            ->get(route('admin.rental-dashboard'))
            ->assertForbidden();
    }

    public function test_authorized_staff_see_the_dashboard(): void
    {
        $this->actingAs($this->admin->fresh())
            ->get(route('admin.rental-dashboard'))
            ->assertOk()
            ->assertSee('داشبورد اجاره', false);
    }

    // ── Empty state ──────────────────────────────────────────────────────

    public function test_an_empty_system_reports_zeroes_and_says_so(): void
    {
        $metrics = app(RentalDashboardMetrics::class)->snapshot();

        $this->assertSame(0, $metrics['applications']['active']);
        $this->assertSame(0, $metrics['operations']['open_total']);
        $this->assertSame(0, $metrics['closeout']['returned_total']);
        $this->assertSame(0, $metrics['damage']['unpaid_amount']);
        $this->assertSame(0, $metrics['settlement']['credited_amount']);
        $this->assertSame(0, $metrics['late']['overdue_count']);
        $this->assertSame(0, $metrics['integrity']['total']);
        $this->assertTrue($metrics['recent_operations']->isEmpty());

        $this->actingAs($this->admin->fresh())
            ->get(route('admin.rental-dashboard'))
            ->assertOk()
            ->assertSee('هیچ اجاره‌ای با تأخیر نیست.', false)
            ->assertSee('هیچ مغایرتی یافت نشد.', false)
            ->assertSee('هنوز عملیاتی تکمیل نشده است.', false);
    }

    // ── Real data ────────────────────────────────────────────────────────

    public function test_the_lifecycle_and_operation_counts_match_the_real_chain(): void
    {
        // One rental actively out with a customer.
        [$active] = $this->activeRental('09151000010', 'DASH-A');

        // One waiting for a human's final approval.
        $this->signedApplication(
            User::create(['full_name' => 'در انتظار', 'mobile' => '09151000011', 'status' => 'active']),
            '13579',
            $this->uniqueNationalCode(),
            $this->nextSayadId(),
        );

        $metrics = app(RentalDashboardMetrics::class)->snapshot();

        $this->assertSame(1, $metrics['applications']['active']);
        $this->assertSame(1, $metrics['applications']['awaiting_approval']);
        $this->assertCount(1, $metrics['applications']['awaiting_approval_queue']);
        $this->assertSame(0, $metrics['applications']['returned']);

        // The active rental's own device is out with the customer.
        $this->assertSame(1, $metrics['fleet']['with_customers']);
        $this->assertGreaterThanOrEqual(1, $metrics['fleet']['rentable']);

        // Its delivery is done; the return has not been opened yet.
        $this->assertSame(RentalApplicationState::Active, $active->fresh()->state);
        $this->assertSame(0, $metrics['operations']['failed']);
    }

    public function test_closeout_damage_note_and_settlement_figures_are_real(): void
    {
        [$application, $reservation, $inspection] = $this->inspectedReturn('09151000020', 'DASH-B');

        $this->damage->record($inspection, $this->admin, 2_000_000);

        $metrics = app(RentalDashboardMetrics::class)->snapshot();

        $this->assertSame(1, $metrics['closeout']['returned_total']);
        $this->assertSame(0, $metrics['closeout']['missing_return_inspection']);
        $this->assertSame(1, $metrics['closeout']['unresolved_damage']);
        $this->assertSame(1, $metrics['closeout']['unresolved_note']);
        $this->assertSame(0, $metrics['closeout']['awaiting_owner_return'], 'a GamePek device goes back to nobody');

        // Assessed but not received: two different figures, never merged.
        $this->assertSame(1, $metrics['damage']['unpaid_count']);
        $this->assertSame(2_000_000, $metrics['damage']['unpaid_amount']);
        $this->assertSame(0, $metrics['damage']['received_count']);
        $this->assertSame(0, $metrics['damage']['received_amount']);

        $this->assertSame(1, $metrics['notes']['held']);
        $this->assertSame(0, $metrics['notes']['retained']);

        // Nothing calculated, so nothing may be claimed as settled.
        $this->assertSame(0, $metrics['settlement']['calculated_count']);
        $this->assertSame(0, $metrics['settlement']['credited_amount']);

        // Now the customer pays: the money moves from one column to the other.
        $this->notes->retainByGamePek($application->refresh(), $this->admin);
        $after = app(RentalDashboardMetrics::class)->snapshot();
        $this->assertSame(1, $after['notes']['retained']);

        $this->damage->recordPayment($application->refresh(), $this->admin, 'REF-DASH');
        $paid = app(RentalDashboardMetrics::class)->snapshot();

        $this->assertSame(0, $paid['damage']['unpaid_count']);
        $this->assertSame(1, $paid['damage']['received_count']);
        $this->assertSame(2_000_000, $paid['damage']['received_amount']);
        $this->assertSame(0, $paid['closeout']['unresolved_damage']);

        // A paid retained note that has gone home is no longer "retained".
        $this->notes->returnToCustomer($application->refresh(), $this->admin);
        $home = app(RentalDashboardMetrics::class)->snapshot();
        $this->assertSame(0, $home['notes']['retained']);
        $this->assertSame(1, $home['notes']['returned']);

        $this->assertNotNull($reservation);
    }

    public function test_an_overdue_rental_is_listed_without_claiming_any_money(): void
    {
        [$application, $reservation] = $this->activeRental('09151000030', 'DASH-C');

        $this->travelTo($reservation->end_date->copy()->addDays(3));

        $metrics = app(RentalDashboardMetrics::class)->snapshot();

        $this->assertSame(1, $metrics['late']['overdue_count']);
        $this->assertCount(1, $metrics['late']['queue']);

        // The late section carries no amount at all -- the recipient is
        // deferred, so no figure here may read as money owed to anyone.
        $this->assertArrayNotHasKey('fee_total', $metrics['late']);
        $this->assertArrayNotHasKey('late_amount', $metrics['late']);

        $response = $this->actingAs($this->admin->fresh())
            ->get(route('admin.rental-dashboard'))->assertOk();

        $response->assertSee(persian_number($application->application_number), false);
        $response->assertSee('دریافت‌کننده آن هنوز تعیین نشده', false);
        // No claim that a late fee was ever paid or settled.
        $response->assertDontSee('تأخیر پرداخت‌شده', false);
        $this->assertSame(0, WalletTransaction::count());
    }

    // ── The page is inert ────────────────────────────────────────────────

    public function test_opening_the_dashboard_mutates_nothing(): void
    {
        [$application, $reservation, $inspection] = $this->inspectedReturn('09151000040', 'DASH-D');
        $this->damage->record($inspection, $this->admin, 1_000_000);

        $before = [
            'state' => $application->fresh()->state,
            'transitions' => RentalApplicationTransition::count(),
            'operations' => RentalOperation::count(),
            'reservations' => DB::table('rental_reservations')->count(),
            'returned_on' => $reservation->fresh()->returned_on?->toDateString(),
            'wallet' => WalletTransaction::count(),
            'payments' => RentalDamagePayment::count(),
            'devices' => DB::table('devices')->get()->toJson(),
        ];

        $this->actingAs($this->admin->fresh());
        $this->get(route('admin.rental-dashboard'))->assertOk();
        $this->get(route('admin.rental-dashboard'))->assertOk();
        $this->get(route('admin.rental-dashboard'))->assertOk();

        $this->assertSame($before['state'], $application->fresh()->state);
        $this->assertSame($before['transitions'], RentalApplicationTransition::count());
        $this->assertSame($before['operations'], RentalOperation::count());
        $this->assertSame($before['reservations'], DB::table('rental_reservations')->count());
        $this->assertSame($before['returned_on'], $reservation->fresh()->returned_on?->toDateString());
        $this->assertSame($before['wallet'], WalletTransaction::count());
        $this->assertSame($before['payments'], RentalDamagePayment::count());
        $this->assertSame($before['devices'], DB::table('devices')->get()->toJson());
    }

    public function test_the_dashboard_does_not_run_a_query_per_row(): void
    {
        // Five rentals at different rungs, so every section has rows to load.
        for ($i = 0; $i < 5; $i++) {
            $this->activeRental('0915200'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'DASH-N'.$i);
        }

        // Measured WITHOUT the integrity section: the reconciler is a
        // record-by-record diagnostic whose cost is meant to grow with the
        // data. What must stay flat is the dashboard's own work.
        DB::enableQueryLog();
        app(RentalDashboardMetrics::class)->snapshot(withIntegrity: false);
        $withFive = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 5; $i < 10; $i++) {
            $this->activeRental('0915200'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'DASH-N'.$i);
        }

        DB::flushQueryLog();
        app(RentalDashboardMetrics::class)->snapshot(withIntegrity: false);
        $withTen = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Doubling the data must not move the query count at all: every
        // section is an aggregate or one bounded eager-loaded list.
        $this->assertSame(
            $withFive,
            $withTen,
            "query count grew from {$withFive} to {$withTen} when the data doubled — a per-row query crept in",
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /** @return array{0: RentalApplication, 1: RentalReservation} */
    private function activeRental(string $mobile, string $serial): array
    {
        $customer = User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);

        // Every customer needs their own national code and sayad id: both are
        // unique, and these fixtures build several real chains.
        $application = $this->signedApplication(
            $customer,
            '13579',
            $this->uniqueNationalCode(),
            $this->nextSayadId(),
        );
        $this->orchestrator->approve($application, $this->admin, null);

        $reservation = $application->reservation()->firstOrFail();
        $device = $this->device($application->product, $serial);

        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)->firstOrFail();
        $this->operations->attachDevice($pickup, $device, $this->admin);

        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);

        return [$application->refresh(), $reservation->refresh()];
    }

    /** @return array{0: RentalApplication, 1: RentalReservation, 2: RentalInspection} */
    private function inspectedReturn(string $mobile, string $serial): array
    {
        [$application, $reservation] = $this->activeRental($mobile, $serial);

        $this->notes->receive($application->refresh(), $this->admin);

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        $inspection = app(RentalInspectionService::class)->record($return->refresh(), $this->admin, 'بررسی بازگشت');

        return [$application->refresh(), $reservation->refresh(), $inspection];
    }

    /** A fresh, well-formed 16-digit sayad id; they are unique per guarantee. */
    private function nextSayadId(): string
    {
        static $n = 0;

        return '1'.str_pad((string) (++$n), 15, '0', STR_PAD_LEFT);
    }

    /** The fixture product's single GamePek unit, so capacity stays honest. */
    private function device(Product $product, string $serial): Device
    {
        return Device::where('product_id', $product->id)->rentable()->firstOrFail();
    }
}
