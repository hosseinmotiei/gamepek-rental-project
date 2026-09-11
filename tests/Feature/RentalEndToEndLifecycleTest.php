<?php

namespace Tests\Feature;

use App\Enums\CustodyActor;
use App\Enums\CustodyTransferState;
use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationState;
use App\Enums\RentalOperationType;
use App\Models\Device;
use App\Models\DeviceCustodyTransfer;
use App\Models\GuaranteeInquiry;
use App\Models\Product;
use App\Models\RentalApplicationTransition;
use App\Models\RentalInspection;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\OperationCustodyReconciler;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalClosureReadiness;
use App\Services\Rental\RentalOperationService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The whole confirmed physical lifecycle, driven through the real HTTP routes:
 *
 *   Approval -> Delivery -> Customer acceptance -> Active -> Customer return
 *   -> Returned -> Inspection -> GamePek -> Owner
 *
 * plus the invalid and duplicate actions around it, and the consistency guards
 * between one rental's owner return and the next rental of the same console.
 *
 * Nothing financial is exercised: no deposit, refund, damage amount,
 * settlement or closure exists to test.
 */
class RentalEndToEndLifecycleTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $admin;

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
    }

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function ownerDevice(Product $product, string $mobile, string $serial): Device
    {
        $devices = app(DeviceRegistrationService::class);
        $user = User::create(['full_name' => 'مالک '.$serial, 'mobile' => $mobile, 'status' => 'active']);

        return $devices->approve(
            $devices->registerForOwner($devices->ensureOwnerProfile($user), $product, $serial),
            $this->admin,
        )->refresh();
    }

    private function operation(RentalReservation $reservation, RentalOperationType $type): RentalOperation
    {
        return RentalOperation::where('rental_reservation_id', $reservation->id)
            ->where('type', $type->value)
            ->firstOrFail();
    }

    private function transitionsTo(int $applicationId, RentalApplicationState $state): int
    {
        return RentalApplicationTransition::where('rental_application_id', $applicationId)
            ->where('to_state', $state->value)
            ->count();
    }

    // ── The full flow ────────────────────────────────────────────────────

    public function test_the_complete_confirmed_lifecycle_through_the_http_routes(): void
    {
        $customer = $this->customer('09170003001');
        $application = $this->signedApplication($customer);
        $device = $this->ownerDevice($application->product, '09170003002', 'E2E-001');
        $ownerUser = $device->owner->user;

        // 1. Final approval.
        $this->actingAs($this->admin)
            ->post(route('admin.rental-applications.approve', $application), ['note' => 'تأیید'])
            ->assertSessionHas('success');
        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);

        $reservation = $application->reservation()->firstOrFail();

        // 2. GamePek collects the owner's console (a human names the device).
        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
        $this->actingAs($this->admin)->post(route('admin.operations.device', $pickup), ['device_id' => $device->id])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.operations.start', $pickup))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.operations.custody', $pickup))->assertSessionHas('success');
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());

        // Still only Approved: collecting the device starts nothing.
        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);

        // 3. Delivery at the customer's door, with the door check.
        $this->actingAs($this->admin)->post(route('admin.rental-applications.delivery.open', $application))->assertRedirect();
        $delivery = $this->operation($reservation, RentalOperationType::CustomerDelivery);
        $this->actingAs($this->admin)->post(route('admin.operations.start', $delivery))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.operations.custody', $delivery), ['notes' => 'بررسی در محل: سالم'])
            ->assertSessionHas('success');

        // 4. Active -- exactly once, only because of the delivery.
        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);
        $this->assertSame(CustodyActor::Customer, $device->fresh()->currentCustody());
        $this->assertSame(1, $this->transitionsTo($application->id, RentalApplicationState::Active));

        // 5. Customer acceptance of the delivery record, from their own page.
        $page = $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();
        $page->assertSee('دستگاه به شما تحویل داده شد.');
        $page->assertSee('تأیید شده');
        $page->assertDontSee('بررسی در محل: سالم');
        $page->assertDontSee($device->serial_number);
        $page->assertDontSee($ownerUser->full_name);

        $this->actingAs($customer)
            ->post(route('rental.applications.handover.acknowledge', $application), ['operation' => $delivery->id])
            ->assertRedirect();
        $this->assertSame(CustodyTransferState::Acknowledged, $delivery->custodyTransfer()->firstOrFail()->state);

        // Viewing pages never moves the lifecycle.
        $this->actingAs($customer)->get(route('rental.applications.show', $application))->assertOk();
        $this->actingAs($customer)->get(route('rental.dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rental-applications.show', $application))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rental-applications.index'))->assertOk();
        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);

        // 6. Customer return, coordinated through support and recorded by staff.
        $this->actingAs($this->admin)->post(route('admin.rental-applications.return.open', $application))->assertRedirect();
        $return = $this->operation($reservation, RentalOperationType::CustomerReturn);
        $this->actingAs($this->admin)->post(route('admin.operations.start', $return))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.operations.custody', $return), ['notes' => 'دریافت در محل'])
            ->assertSessionHas('success');

        // 7. Returned -- exactly once.
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertSame(1, $this->transitionsTo($application->id, RentalApplicationState::Returned));
        $this->assertNotNull($return->custodyTransfer()->firstOrFail()->ownerDefectReportDeadline());

        // A duplicate return record is refused and changes nothing.
        $this->actingAs($this->admin)->post(route('admin.operations.custody', $return))->assertSessionHas('error');
        $this->assertSame(1, DeviceCustodyTransfer::where('rental_operation_id', $return->id)->count());
        $this->assertSame(1, $this->transitionsTo($application->id, RentalApplicationState::Returned));

        // 8. Inspection of the returned device (staff-only evidence).
        $this->actingAs($this->admin)
            ->post(route('admin.operations.inspections.store', $return), ['findings' => 'بررسی کارشناس: خط‌وخش جزئی'])
            ->assertSessionHas('success');
        $this->assertSame(1, RentalInspection::where('rental_operation_id', $return->id)->count());
        $this->actingAs($customer)->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertSee('دستگاه توسط گیم‌پک از شما دریافت شد.')
            ->assertDontSee('خط‌وخش جزئی');

        // 9. GamePek -> owner.
        $this->actingAs($this->admin)->post(route('admin.rental-applications.owner-return.open', $application))->assertRedirect();
        $ownerReturn = $this->operation($reservation, RentalOperationType::OwnerReturn);
        $this->actingAs($this->admin)->post(route('admin.operations.start', $ownerReturn))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.operations.custody', $ownerReturn))->assertSessionHas('success');

        $this->assertSame(CustodyActor::Owner, $device->fresh()->currentCustody());

        $this->actingAs($ownerUser)->post(route('owner.operations.acknowledge', $ownerReturn))->assertSessionHas('success');
        $this->assertSame(CustodyTransferState::Acknowledged, $ownerReturn->custodyTransfer()->firstOrFail()->state);

        // Returned does not auto-close, and the data reconciles cleanly.
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertSame(0, $this->transitionsTo($application->id, RentalApplicationState::Closed));
        $this->assertSame([], app(OperationCustodyReconciler::class)->findings()->all());

        // 10. Closure readiness: every physical step is reported done; the
        // close-out steps (damage outcome, note, owner credit) are not, and
        // nothing closes or pays on its own. RentalCloseoutTest runs the
        // close-out itself.
        $readiness = app(RentalClosureReadiness::class)->check($application->refresh());
        $items = collect($readiness['items'])->pluck('status', 'key');

        $this->assertSame('satisfied', $items['customer_return']);
        $this->assertSame('satisfied', $items['return_inspection']);
        $this->assertSame('satisfied', $items['owner_return']);
        $this->assertSame('missing', $items['damage_resolution']);
        $this->assertSame('missing', $items['guarantee_note']);
        $this->assertSame('missing', $items['settlement']);
        $this->assertFalse($readiness['ready']);

        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertSame(0, RentalSettlement::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    // ── Invalid and duplicate actions ────────────────────────────────────

    public function test_out_of_order_and_repeated_lifecycle_actions_are_refused(): void
    {
        $customer = $this->customer('09170003003');
        $application = $this->signedApplication($customer);

        // Nothing physical can start before approval.
        $this->actingAs($this->admin)->post(route('admin.rental-applications.delivery.open', $application))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.rental-applications.return.open', $application))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.rental-applications.owner-return.open', $application))->assertSessionHas('error');
        $this->assertSame(0, RentalOperation::where('type', '!=', RentalOperationType::OwnerDevicePickup->value)->count());

        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);

        // Approving twice writes one approval.
        $this->actingAs($this->admin)->post(route('admin.rental-applications.approve', $application))->assertRedirect();
        $this->assertSame(1, $this->transitionsTo($application->id, RentalApplicationState::Approved));

        // Delivery needs an allocated device; return and owner return need a
        // rental that has actually progressed that far.
        $this->actingAs($this->admin)->post(route('admin.rental-applications.delivery.open', $application))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.rental-applications.return.open', $application))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.rental-applications.owner-return.open', $application))->assertSessionHas('error');

        // Rejection and cancellation are no longer possible after approval --
        // and never after delivery.
        $this->assertFalse(RentalApplicationState::Active->canTransitionTo(RentalApplicationState::Cancelled));
        $this->assertFalse(RentalApplicationState::Active->canTransitionTo(RentalApplicationState::Approved));
        $this->assertFalse(RentalApplicationState::Returned->canTransitionTo(RentalApplicationState::Active));
        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
    }

    public function test_tampered_and_foreign_identifiers_are_refused_across_the_flow(): void
    {
        $customer = $this->customer('09170003004');
        $application = $this->signedApplication($customer);
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $reservation = $application->reservation()->firstOrFail();
        $device = $this->ownerDevice($application->product, '09170003005', 'E2E-003');

        // A device of a DIFFERENT model cannot be attached, whatever is posted.
        $otherProduct = $this->makeRentableProduct();
        $wrongModel = $this->ownerDevice($otherProduct, '09170003006', 'E2E-003-B');
        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
        $this->actingAs($this->admin)->post(route('admin.operations.device', $pickup), ['device_id' => $wrongModel->id])
            ->assertSessionHas('error');
        $this->assertNull($pickup->refresh()->device_id);

        // Non-existent ids never reach a service.
        $this->actingAs($this->admin)->post(route('admin.operations.device', $pickup), ['device_id' => 999999])
            ->assertSessionHasErrors('device_id');
        $this->actingAs($this->admin)->post(route('admin.operations.start', 999999))->assertNotFound();

        // A foreign owner cannot see or confirm this pickup.
        $this->actingAs($this->admin)->post(route('admin.operations.device', $pickup), ['device_id' => $device->id]);
        $this->actingAs($wrongModel->owner->user)->get(route('owner.operations.show', $pickup))->assertForbidden();
        $this->actingAs($wrongModel->owner->user)->post(route('owner.operations.acknowledge', $pickup))->assertForbidden();

        // A foreign customer cannot read or act on this application.
        $intruder = $this->customer('09170003007');
        $this->actingAs($intruder)->get(route('rental.applications.show', $application))->assertForbidden();
        $this->actingAs($intruder)
            ->post(route('rental.applications.handover.acknowledge', $application), ['operation' => $pickup->id])
            ->assertForbidden();
    }

    // ── Consistency between one rental's owner return and the next rental ─

    /** Same console, a later non-overlapping rental. See the other test file. */
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

    /** Runs the first rental to Returned through the services. */
    private function returnedRental(string $mobile, string $ownerMobile, string $serial): array
    {
        $operations = app(RentalOperationService::class);
        $custody = app(DeviceCustodyService::class);

        $application = $this->signedApplication($this->customer($mobile));
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $reservation = $application->reservation()->firstOrFail();
        $device = $this->ownerDevice($application->product, $ownerMobile, $serial);

        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
        $operations->attachDevice($pickup, $device, $this->admin);
        $operations->start($pickup->refresh(), $this->admin);
        $custody->requestFromOwner($pickup->refresh(), $this->admin);
        $custody->recordHandoverToGamePek($pickup->refresh(), $this->admin);

        foreach ([RentalOperationType::CustomerDelivery, RentalOperationType::CustomerReturn] as $type) {
            $op = $type === RentalOperationType::CustomerDelivery
                ? $operations->openDeliveryForReservation($reservation->refresh(), $this->admin)
                : $operations->openReturnForReservation($reservation->refresh(), $this->admin);
            $operations->start($op->refresh(), $this->admin);

            $type === RentalOperationType::CustomerDelivery
                ? $custody->requestDeliveryToCustomer($op->refresh(), $this->admin)
                : $custody->requestReturnFromCustomer($op->refresh(), $this->admin);

            $type === RentalOperationType::CustomerDelivery
                ? $custody->recordDeliveryToCustomer($op->refresh(), $this->admin)
                : $custody->recordReturnToGamePek($op->refresh(), $this->admin);
        }

        return [$application->refresh(), $reservation->refresh(), $device->refresh()];
    }

    public function test_an_owner_return_is_refused_while_a_later_rental_relies_on_gamepek_holding_the_device(): void
    {
        [, $first, $device] = $this->returnedRental('09170003008', '09170003009', 'E2E-004');
        $operations = app(RentalOperationService::class);

        // The next rental is promised the console GamePek still holds.
        $second = $this->laterRentalOfSameDevice($device, $first, '09170003010');
        $nextPickup = $operations->attachDevice($this->operation($second, RentalOperationType::OwnerDevicePickup), $device, $this->admin);
        $this->assertSame(RentalOperationState::NotRequired, $nextPickup->state);

        // Refused as early as possible: not even an owner-return task is
        // opened, so nothing stale is left in the queue.
        try {
            $operations->openOwnerReturnForReservation($first, $this->admin);
            $this->fail('The device is held for another rental and must not go home.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('اجاره دیگری', $e->getMessage());
        }

        $this->assertSame(0, RentalOperation::where('type', RentalOperationType::OwnerReturn->value)->count());
        $this->assertSame(CustodyActor::GamePek, $device->fresh()->currentCustody());
    }

    public function test_a_device_on_its_way_back_to_its_owner_cannot_be_allocated_to_a_new_rental(): void
    {
        [, $first, $device] = $this->returnedRental('09170003011', '09170003012', 'E2E-005');
        $operations = app(RentalOperationService::class);

        $ownerReturn = $operations->openOwnerReturnForReservation($first, $this->admin);
        $this->assertSame(RentalOperationState::Scheduled, $ownerReturn->state);

        $second = $this->laterRentalOfSameDevice($device, $first, '09170003013');
        $nextPickup = $this->operation($second, RentalOperationType::OwnerDevicePickup);

        try {
            $operations->attachDevice($nextPickup, $device, $this->admin);
            $this->fail('A device leaving for its owner must not be promised to a new rental.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('بازگرداندن به مالک', $e->getMessage());
        }

        $this->assertNull($nextPickup->refresh()->device_id);

        // Once it is home, the next rental gets a real pickup from the owner.
        $operations->start($ownerReturn->refresh(), $this->admin);
        app(DeviceCustodyService::class)->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        app(DeviceCustodyService::class)->recordReturnToOwner($ownerReturn->refresh(), $this->admin);

        $attached = $operations->attachDevice($nextPickup->refresh(), $device, $this->admin);
        $this->assertSame(RentalOperationState::Scheduled, $attached->state);
    }
}
