<?php

namespace Tests\Feature;

use App\Enums\DeviceState;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\GuaranteeNoteEvent;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\RentalDamagePayment;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\GuaranteeNoteService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalDamageAssessmentService;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The boundaries where an owner's rights end and an undecided policy begins.
 *
 * Each test here proves one of two things: either a CONFIRMED rule is enforced
 * server-side (an owner cannot take a device out from under a rental), or an
 * UNDECIDED policy is failing closed rather than having quietly acquired a
 * default (owner cancellation, waitlists, a damage-payment deadline).
 *
 * The second kind matters most. A deferred decision that silently becomes
 * behaviour is worse than one that refuses, because nobody discovers it until
 * it has already been applied to a real customer.
 */
class OwnerPolicyBoundaryTest extends TestCase
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

    // ── C: owner cancellation / reclaim ──────────────────────────────────

    public function test_an_owner_cannot_disable_a_device_that_is_in_a_customers_hands(): void
    {
        [$application, , $device, $ownerUser] = $this->activeOwnerRental('09161000001', '09169000001', 'OPB-A');

        $response = $this->actingAs($ownerUser->fresh())
            ->post(route('owner.devices.disable', $device), ['reason' => 'می‌خواهم دستگاهم را پس بگیرم']);

        // Refused server-side, whether by the policy (403) or by the service's
        // own re-check behind it (redirect with an error). Both are refusals;
        // what matters is that the device does not leave the fleet.
        $this->assertContains($response->status(), [403, 302]);

        $this->assertSame(DeviceState::Approved, $device->fresh()->state,
            'a device in a customer\'s hands must stay in the fleet');
        $this->assertSame(RentalApplicationState::Active, $application->fresh()->state,
            'an owner action must not touch the rental lifecycle');
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());
    }

    public function test_an_owner_cannot_disable_a_device_committed_to_a_paid_future_rental(): void
    {
        // Approved and allocated, but not yet delivered: the customer has paid
        // and the device is promised to them.
        [$application, , $device, $ownerUser] = $this->allocatedOwnerRental('09161000002', '09169000002', 'OPB-B');

        $this->assertSame(RentalApplicationState::Approved, $application->fresh()->state);

        $response = $this->actingAs($ownerUser->fresh())
            ->post(route('owner.devices.disable', $device), ['reason' => 'نظرم عوض شد']);

        $this->assertContains($response->status(), [403, 302]);

        $this->assertSame(DeviceState::Approved, $device->fresh()->state,
            'a device promised to a paid booking may not leave the fleet');

        // And the promise itself is untouched.
        $reservation = $application->fresh()->reservation;
        $this->assertSame($device->id, $reservation->device_id);
        $this->assertTrue($device->fresh()->isCommittedToLiveRental());
    }

    public function test_there_is_no_endpoint_by_which_anyone_can_cancel_a_live_rental(): void
    {
        // UNRESOLVED POLICY: owner cancellation has no confirmed outcome --
        // no refund rule, no penalty, no replacement-device rule. The safe
        // state is that the transition is not reachable at all: cancel() exists
        // on the orchestrator for pre-approval use, and NO route calls it.
        $cancelRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null
                && str_contains($name, 'rental')
                && str_contains($name, 'cancel'))
            ->values()->all();

        $this->assertSame([], $cancelRoutes,
            'a rental-cancellation endpoint would be an answer to an undecided policy');

        // The owner's whole surface is: their profile, their devices, their
        // own pickups. Nothing there reaches another party's rental.
        $ownerRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'owner.'))
            ->map(fn ($route) => $route->getName())
            ->values()->all();

        foreach ($ownerRoutes as $name) {
            $this->assertStringStartsNotWith('owner.rental', $name);
        }
    }

    public function test_an_owner_viewing_their_pages_changes_no_state(): void
    {
        [$application, $reservation, $device, $ownerUser] = $this->activeOwnerRental('09161000003', '09169000003', 'OPB-C');

        $before = [
            'state' => $application->fresh()->state,
            'transitions' => RentalApplicationTransition::count(),
            'device' => $device->fresh()->state,
            'operations' => RentalOperation::count(),
            'custody' => DB::table('device_custody_transfers')->count(),
        ];

        $this->actingAs($ownerUser->fresh());
        $this->get(route('owner.dashboard'))->assertOk();
        $this->get(route('owner.devices.show', $device))->assertOk();
        $this->get(route('owner.operations.index'))->assertOk();

        $this->assertSame($before['state'], $application->fresh()->state);
        $this->assertSame($before['transitions'], RentalApplicationTransition::count());
        $this->assertSame($before['device'], $device->fresh()->state);
        $this->assertSame($before['operations'], RentalOperation::count());
        $this->assertSame($before['custody'], DB::table('device_custody_transfers')->count());
        $this->assertNotNull($reservation);
    }

    public function test_an_owner_cannot_reach_another_owners_device_or_a_gamepek_one(): void
    {
        [, , $deviceA, $ownerA] = $this->activeOwnerRental('09161000004', '09169000004', 'OPB-D');

        $strangerUser = User::create(['full_name' => 'مالک دیگر', 'mobile' => '09169000005', 'status' => 'active']);
        app(DeviceRegistrationService::class)->ensureOwnerProfile($strangerUser);

        $this->actingAs($strangerUser->fresh())
            ->post(route('owner.devices.disable', $deviceA), ['reason' => 'x'])
            ->assertForbidden();

        $this->actingAs($strangerUser->fresh())
            ->get(route('owner.devices.show', $deviceA))
            ->assertForbidden();

        $this->assertSame(DeviceState::Approved, $deviceA->fresh()->state);
        $this->assertNotNull($ownerA);
    }

    // ── D: capacity, with no invented fallback ───────────────────────────

    public function test_a_product_whose_devices_are_all_busy_is_simply_unavailable_with_no_waitlist(): void
    {
        [, $reservation] = $this->activeOwnerRental('09161000010', '09169000010', 'OPB-E');

        // The fixture product ships with one GamePek unit and this rental runs
        // on the owner's, so take the idle unit out to reach the case under
        // test: every eligible device busy.
        $idle = Device::where('product_id', $reservation->product_id)
            ->rentable()
            ->where('id', '!=', $reservation->device_id)
            ->get();

        foreach ($idle as $device) {
            app(DeviceRegistrationService::class)->disable($device, $this->admin, 'خارج از ناوگان برای این آزمون');
        }

        $availability = app(RentalAvailabilityService::class);
        $start = $reservation->start_date->toDateString();
        $end = $reservation->end_date->toDateString();

        $reservationsBefore = RentalReservation::count();

        $this->assertFalse($availability->isFree($reservation->product_id, $start, $end),
            'the only device is taken for those dates');

        // UNRESOLVED POLICY (C-56): no waitlist, queue, backorder or hold is
        // created by a refused booking. The refusal writes nothing at all.
        $this->assertSame($reservationsBefore, RentalReservation::count());
        $this->assertSame(0, DB::table('rental_reservations')->where('state', 'held')->count());
        $this->assertSame(0, DB::table('rental_reservations')->where('state', 'awaiting_payment')->count());
    }

    public function test_a_disabled_device_is_never_allocated_and_never_counts_as_capacity(): void
    {
        $product = $this->makeRentableProduct();
        $devices = app(DeviceRegistrationService::class);

        $spare = $devices->approve(
            $devices->registerForGamePek($product, 'OPB-SPARE-'.strtoupper(uniqid()), [], $this->admin),
            $this->admin,
        );

        $availability = app(RentalAvailabilityService::class);
        $from = now()->addDays(3)->toDateString();
        $to = now()->addDays(5)->toDateString();

        $this->assertTrue($availability->isFree($product->id, $from, $to));

        // Take every unit out of the fleet: capacity falls to zero, and no
        // fallback device is conjured up.
        foreach (Device::where('product_id', $product->id)->get() as $device) {
            $devices->disable($device, $this->admin, 'تعمیر');
        }

        $this->assertFalse($availability->isFree($product->id, $from, $to));
        $this->assertSame(0, Device::where('product_id', $product->id)->rentable()->count());
        $this->assertSame(DeviceState::Disabled, $spare->fresh()->state);
    }

    // ── F: cancelled / rejected rentals and the note ─────────────────────

    public function test_a_rejected_rental_keeps_its_note_held_and_moves_no_money(): void
    {
        $customer = User::create(['full_name' => 'مشتری', 'mobile' => '09161000020', 'status' => 'active']);
        $application = $this->signedApplication($customer);
        $this->orchestrator->approve($application, $this->admin, null);

        $notes = app(GuaranteeNoteService::class);
        $notes->receive($application->refresh(), $this->admin);

        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'rejected']);

        // UNRESOLVED POLICY: nothing says what happens to a note held for a
        // rental that never ran. Every outcome therefore refuses, and the note
        // stays exactly where it is -- with its history intact.
        foreach (['returnToCustomer', 'transferToOwner', 'retainByGamePek'] as $method) {
            try {
                $notes->{$method}($application->refresh(), $this->admin);
                $this->fail($method.' must not resolve a rejected rental\'s note.');
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->assertSame(1, GuaranteeNoteEvent::where('rental_application_id', $application->id)->count(),
            'note history is append-only and nothing was deleted or duplicated');
        $this->assertSame(0, RentalSettlement::count());
        $this->assertSame(0, RentalDamagePayment::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, DB::table('device_custody_transfers')->count());
    }

    // ── G: no damage-payment deadline exists ─────────────────────────────

    public function test_nothing_expires_forgives_or_transfers_a_damage_claim_because_time_passed(): void
    {
        [$application, , $inspection] = $this->returnedGamePekRental('09161000030', 'OPB-F');

        $damage = app(RentalDamageAssessmentService::class);
        $notes = app(GuaranteeNoteService::class);

        $damage->record($inspection, $this->admin, 4_000_000);
        $notes->retainByGamePek($application->refresh(), $this->admin);

        // A year later, with no staff action in between.
        $this->travel(365)->days();

        $status = $damage->statusFor($application->id);

        $this->assertSame(RentalDamageAssessmentService::UNPAID, $status['status'],
            'an unpaid damage is not forgiven by time');
        $this->assertSame(4_000_000, $status['assessment']->amount,
            'the assessed amount does not drift');
        $this->assertSame(
            [GuaranteeNoteEvent::RECEIVED, GuaranteeNoteEvent::RETAINED_BY_GAMEPEK],
            GuaranteeNoteEvent::where('rental_application_id', $application->id)->orderBy('id')->pluck('event')->all(),
            'no deadline transferred the note to anyone',
        );

        // And the customer can still pay, exactly as before.
        $payment = $damage->recordPayment($application->refresh(), $this->admin, 'REF-LATE-BUT-VALID');

        $this->assertSame(4_000_000, $payment->amount);
        $this->assertSame(1, WalletTransaction::count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * Approved, with an owner's device attached but not yet delivered.
     *
     * @return array{0: RentalApplication, 1: RentalReservation, 2: Device, 3: User}
     */
    private function allocatedOwnerRental(string $mobile, string $ownerMobile, string $serial): array
    {
        $customer = User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);

        $application = $this->signedApplication($customer);
        $this->orchestrator->approve($application, $this->admin, null);

        $reservation = $application->reservation()->firstOrFail();

        $devices = app(DeviceRegistrationService::class);
        $ownerUser = User::create(['full_name' => 'مالک', 'mobile' => $ownerMobile, 'status' => 'active']);
        $device = $devices->approve(
            $devices->registerForOwner($devices->ensureOwnerProfile($ownerUser), $application->product, $serial),
            $this->admin,
        )->refresh();

        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)->firstOrFail();
        $this->operations->attachDevice($pickup, $device, $this->admin);

        return [$application->refresh(), $reservation->refresh(), $device->fresh(), $ownerUser];
    }

    /**
     * The same rental, delivered: the device is in the customer's hands.
     *
     * @return array{0: RentalApplication, 1: RentalReservation, 2: Device, 3: User}
     */
    private function activeOwnerRental(string $mobile, string $ownerMobile, string $serial): array
    {
        [$application, $reservation, $device, $ownerUser] = $this->allocatedOwnerRental($mobile, $ownerMobile, $serial);

        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)->firstOrFail();
        $this->operations->start($pickup->refresh(), $this->admin);
        $this->custody->requestFromOwner($pickup->refresh(), $this->admin);
        $this->custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);

        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);

        return [$application->refresh(), $reservation->refresh(), $device->fresh(), $ownerUser];
    }

    /**
     * A GamePek-owned rental, returned and inspected.
     *
     * @return array{0: RentalApplication, 1: RentalReservation, 2: RentalInspection}
     */
    private function returnedGamePekRental(string $mobile, string $serial): array
    {
        $customer = User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);

        $application = $this->signedApplication($customer);
        $this->orchestrator->approve($application, $this->admin, null);
        app(GuaranteeNoteService::class)->receive($application->refresh(), $this->admin);

        $reservation = $application->reservation()->firstOrFail();
        $device = Device::where('product_id', $application->product->id)->rentable()->firstOrFail();

        $pickup = RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', RentalOperationType::OwnerDevicePickup->value)->firstOrFail();
        $this->operations->attachDevice($pickup, $device, $this->admin);

        $delivery = $this->operations->openDeliveryForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($delivery->refresh(), $this->admin);
        $this->custody->requestDeliveryToCustomer($delivery->refresh(), $this->admin);
        $this->custody->recordDeliveryToCustomer($delivery->refresh(), $this->admin);

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        $inspection = app(RentalInspectionService::class)->record($return->refresh(), $this->admin, 'بررسی بازگشت');

        return [$application->refresh(), $reservation->refresh(), $inspection];
    }
}
