<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Enums\RentalOperationType;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\GuaranteeInquiry;
use App\Models\Product;
use App\Models\RentalDamageAssessment;
use App\Models\RentalOperation;
use App\Models\RentalReservation;
use App\Models\RentalSettlement;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\DeviceCustodyService;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalClosureReadiness;
use App\Services\Rental\RentalDamageAssessmentService;
use App\Services\Rental\RentalInspectionService;
use App\Services\Rental\RentalOperationService;
use App\Services\Rental\RentalSettlementService;
use App\Support\Rental\SettlementSplit;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Settlement calculation, damage assessment, closure readiness and the
 * current early-return behaviour.
 *
 * CONFIRMED and tested: 35% GamePek / 65% owner (C-26/C-27); the expert
 * determines the damage amount (C-39); Returned never closes by itself.
 *
 * NOT decided, so tested only as refusals or reports: the amount the split
 * applies to, any payout or wallet movement, deposit, refund, closure.
 */
class RentalFinanceFoundationTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $admin;

    private RentalOperationService $operations;

    private DeviceCustodyService $custody;

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
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function operation(RentalReservation $reservation, RentalOperationType $type): RentalOperation
    {
        return RentalOperation::where('rental_reservation_id', $reservation->id)->where('type', $type->value)->firstOrFail();
    }

    private function device(Product $product, ?string $ownerMobile, string $serial): Device
    {
        $devices = app(DeviceRegistrationService::class);

        $device = $ownerMobile === null
            ? $devices->registerForGamePek($product, $serial, [], $this->admin)
            : $devices->registerForOwner(
                $devices->ensureOwnerProfile(User::create(['full_name' => 'مالک '.$serial, 'mobile' => $ownerMobile, 'status' => 'active'])),
                $product,
                $serial,
            );

        return $devices->approve($device, $this->admin)->refresh();
    }

    /** A rental carried to Returned; owner device unless $ownerMobile is null. */
    private function returned(string $mobile, ?string $ownerMobile, string $serial): array
    {
        $customer = $this->customer($mobile);
        $application = $this->signedApplication($customer);
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $reservation = $application->reservation()->firstOrFail();
        $device = $this->device($application->product, $ownerMobile, $serial);

        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
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

        $return = $this->operations->openReturnForReservation($reservation->refresh(), $this->admin);
        $this->operations->start($return->refresh(), $this->admin);
        $this->custody->requestReturnFromCustomer($return->refresh(), $this->admin);
        $this->custody->recordReturnToGamePek($return->refresh(), $this->admin);

        return [$customer, $application->refresh(), $reservation->refresh(), $device->refresh(), $return->refresh(), $delivery->refresh()];
    }

    private function staffWithRole(string $role, string $mobile): User
    {
        $user = User::create(['full_name' => 'کارمند', 'mobile' => $mobile, 'email' => $mobile.'@test.local', 'status' => 'active']);
        $user->syncRoles([$role]);

        return $user;
    }

    // ── 1. The confirmed 35/65 split ─────────────────────────────────────

    public function test_the_split_is_thirty_five_to_sixty_five_and_always_sums_to_the_gross(): void
    {
        foreach ([
            [100_000, 35_000, 65_000],
            [1_500_000, 525_000, 975_000],
            [0, 0, 0],
            [1, 0, 1],
            [3, 1, 2],
            [999_999, 349_999, 650_000],
        ] as [$gross, $gamepek, $owner]) {
            $split = SettlementSplit::of($gross);

            $this->assertSame($gamepek, $split->gamepekShare, "GamePek share of {$gross}");
            $this->assertSame($owner, $split->ownerShare, "Owner share of {$gross}");
            $this->assertSame($gross, $split->gamepekShare + $split->ownerShare);
            $this->assertSame(3500, $split->commissionBps);
        }
    }

    public function test_rounding_never_gives_the_owner_less_than_their_exact_share(): void
    {
        foreach ([7, 13, 101, 12_345, 777_777] as $gross) {
            $split = SettlementSplit::of($gross);

            $this->assertGreaterThanOrEqual($gross * 0.65, $split->ownerShare);
            $this->assertLessThan(1, $split->ownerShare - $gross * 0.65);
        }
    }

    public function test_a_negative_gross_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettlementSplit::of(-1);
    }

    // ── 2. Settlement calculation: fail-closed, idempotent, moves nothing ─

    public function test_no_settlement_is_calculated_while_the_gross_basis_is_undecided(): void
    {
        [, $application] = $this->returned('09170005001', '09170005002', 'FIN-001');

        // The basis is now confirmed (rental price only) and set by default;
        // an unset basis must STILL fail closed rather than be guessed.
        config()->set('rental.settlement.gross_basis', null);

        try {
            app(RentalSettlementService::class)->calculate($application, $this->admin);
            $this->fail('The basis is undecided; nothing may be calculated.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalSettlement::count());
        // The refusal is audited durably (written before any transaction).
        $this->assertTrue(AuditEvent::forAction('settlement.policy_undefined')->where('result', 'denied')->exists());
    }

    public function test_an_unsupported_basis_is_refused_rather_than_guessed(): void
    {
        [, $application] = $this->returned('09170005003', '09170005004', 'FIN-002');
        config()->set('rental.settlement.gross_basis', 'payable_now');

        $this->expectException(\RuntimeException::class);
        app(RentalSettlementService::class)->calculate($application, $this->admin);
    }

    public function test_a_returned_owner_rental_is_calculated_once_and_no_money_moves(): void
    {
        [, $application, $reservation, $device] = $this->returned('09170005005', '09170005006', 'FIN-003');
        config()->set('rental.settlement.gross_basis', 'rental_total');

        $first = app(RentalSettlementService::class)->calculate($application, $this->admin);
        $second = app(RentalSettlementService::class)->calculate($application, $this->admin);

        $expected = SettlementSplit::of((int) $reservation->rental_total);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RentalSettlement::count());
        $this->assertSame((int) $reservation->rental_total, $first->gross_amount);
        $this->assertSame($expected->gamepekShare, $first->gamepek_share);
        $this->assertSame($expected->ownerShare, $first->owner_share);
        $this->assertSame($device->owner_id, $first->owner_id);
        $this->assertSame(RentalSettlement::STATUS_CALCULATED, $first->status);
        $this->assertStringStartsWith('STL-', $first->reference_number);
        $this->assertNotNull($first->correlation_id);

        // A calculation, never a payment.
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
        $this->assertSame(1, AuditEvent::forAction('settlement.calculated')->count());
    }

    public function test_no_settlement_is_calculated_before_the_device_comes_back(): void
    {
        [, $application, $reservation] = $this->returned('09170005007', '09170005008', 'FIN-004');
        config()->set('rental.settlement.gross_basis', 'rental_total');

        DB::table('rental_applications')->where('id', $application->id)->update(['state' => 'active']);

        $this->expectException(\RuntimeException::class);

        try {
            app(RentalSettlementService::class)->calculate($application->refresh(), $this->admin);
        } finally {
            $this->assertNull($reservation->settlement()->first());
        }
    }

    public function test_a_gamepek_owned_rental_has_no_owner_split(): void
    {
        [, $application] = $this->returned('09170005009', null, 'FIN-005');
        config()->set('rental.settlement.gross_basis', 'rental_total');

        $this->expectException(\RuntimeException::class);
        app(RentalSettlementService::class)->calculate($application, $this->admin);
    }

    public function test_a_settlement_is_immutable_and_its_shares_must_add_up_in_the_database(): void
    {
        [, $application, $reservation, $device] = $this->returned('09170005010', '09170005011', 'FIN-006');
        config()->set('rental.settlement.gross_basis', 'rental_total');
        $settlement = app(RentalSettlementService::class)->calculate($application, $this->admin);

        try {
            $settlement->owner_share = 1;
            $settlement->save();
            $this->fail('A calculated settlement must be immutable.');
        } catch (\LogicException) {
            // expected
        }

        try {
            RentalSettlement::create(['owner_share' => 1]);
            $this->fail('Settlements must not be mass-assignable.');
        } catch (MassAssignmentException) {
            // expected
        }

        // Money created out of nothing is refused below the application.
        DB::table('rental_settlements')->where('id', $settlement->id)->delete();

        $this->expectException(QueryException::class);
        DB::table('rental_settlements')->insert([
            'reference_number' => 'STL-FORGED', 'rental_reservation_id' => $reservation->id,
            'rental_application_id' => $application->id, 'device_id' => $device->id,
            'owner_id' => $device->owner_id, 'gross_basis' => 'rental_total', 'gross_amount' => 100,
            'commission_bps' => 3500, 'gamepek_share' => 35, 'owner_share' => 70,
            'status' => 'calculated', 'calculated_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_the_settlement_route_requires_manage_rental_applications(): void
    {
        [$customer, $application] = $this->returned('09170005012', '09170005013', 'FIN-007');
        config()->set('rental.settlement.gross_basis', 'rental_total');

        $this->actingAs($customer->fresh())
            ->post(route('admin.rental-applications.settlement.calculate', $application))
            ->assertRedirect(route('admin.login'));

        $this->actingAs($this->staffWithRole('product_manager', '09170005014'))
            ->post(route('admin.rental-applications.settlement.calculate', $application))
            ->assertForbidden();

        $this->assertSame(0, RentalSettlement::count());

        $this->actingAs($this->admin)
            ->post(route('admin.rental-applications.settlement.calculate', $application))
            ->assertSessionHas('success');

        $this->assertSame(1, RentalSettlement::count());
    }

    public function test_the_owner_sees_their_calculated_share_as_unpaid_and_nobody_else_sees_it(): void
    {
        [$customer, $application, $reservation, $device] = $this->returned('09170005015', '09170005016', 'FIN-008');
        config()->set('rental.settlement.gross_basis', 'rental_total');
        $settlement = app(RentalSettlementService::class)->calculate($application, $this->admin);
        $pickup = $this->operation($reservation, RentalOperationType::OwnerDevicePickup);
        $share = persian_number(number_format($settlement->owner_share));

        $this->actingAs($device->owner->user)
            ->get(route('owner.operations.show', $pickup))
            ->assertOk()
            ->assertSee($share)
            // Calculated is never shown as paid ("واریز نشده": not credited).
            ->assertSee('واریز نشده');

        $stranger = $this->device($application->product, '09170005017', 'FIN-008-B')->owner->user;
        $this->actingAs($stranger)->get(route('owner.operations.show', $pickup))->assertForbidden();

        // The customer never sees the owner's share.
        $this->actingAs($customer)->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertDontSee($share)
            ->assertDontSee('سهم مالک');
    }

    // ── 3. Damage assessment (C-39) ──────────────────────────────────────

    public function test_an_expert_records_a_damage_amount_against_a_return_inspection(): void
    {
        [, $application, $reservation, $device, $return] = $this->returned('09170005018', '09170005019', 'DMG-001');
        $inspection = app(RentalInspectionService::class)->record($return, $this->admin, 'خط‌وخش روی بدنه');

        $assessment = app(RentalDamageAssessmentService::class)
            ->record($inspection, $this->admin, 450_000, '  تعویض قاب  ', 'IMG-7781');

        $this->assertSame(450_000, $assessment->amount);
        $this->assertSame($application->id, $assessment->rental_application_id);
        $this->assertSame($reservation->id, $assessment->rental_reservation_id);
        $this->assertSame($device->id, $assessment->device_id);
        $this->assertSame($return->id, $assessment->rental_operation_id);
        $this->assertSame('تعویض قاب', $assessment->notes);
        $this->assertSame('IMG-7781', $assessment->evidence_reference);
        $this->assertSame($this->admin->id, $assessment->assessed_by_user_id);

        // Recording an amount charges nobody.
        $this->assertSame(0, WalletTransaction::count());
        $event = AuditEvent::forAction('damage_assessment.recorded')->firstOrFail();
        $this->assertSame(450_000, $event->context['amount']);
    }

    public function test_damage_is_not_assessed_on_a_delivery_inspection(): void
    {
        [, , , , , $delivery] = $this->returned('09170005020', '09170005021', 'DMG-002');
        $inspection = app(RentalInspectionService::class)->record($delivery, $this->admin, 'بررسی در محل');

        $this->expectException(\RuntimeException::class);
        app(RentalDamageAssessmentService::class)->record($inspection, $this->admin, 100);
    }

    public function test_damage_is_refused_when_the_inspection_no_longer_matches_its_rental(): void
    {
        [, $application, , , $return] = $this->returned('09170005022', '09170005023', 'DMG-003');
        $inspection = app(RentalInspectionService::class)->record($return, $this->admin, 'بررسی');
        $other = $this->device($application->product, '09170005024', 'DMG-003-B');

        DB::table('rental_inspections')->where('id', $inspection->id)->update(['device_id' => $other->id]);

        $this->expectException(\RuntimeException::class);

        try {
            app(RentalDamageAssessmentService::class)->record($inspection->refresh(), $this->admin, 100);
        } finally {
            $this->assertSame(0, RentalDamageAssessment::count());
        }
    }

    public function test_a_damage_assessment_is_append_only(): void
    {
        [, , , , $return] = $this->returned('09170005025', '09170005026', 'DMG-004');
        $inspection = app(RentalInspectionService::class)->record($return, $this->admin, 'بررسی');
        $service = app(RentalDamageAssessmentService::class);

        $first = $service->record($inspection, $this->admin, 100_000);
        $service->record($inspection, $this->admin, 120_000, 'بازبینی');

        try {
            $first->amount = 1;
            $first->save();
            $this->fail('An assessment must not be editable.');
        } catch (\LogicException) {
            // expected
        }

        try {
            $first->delete();
            $this->fail('An assessment must not be deletable.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame([100_000, 120_000], RentalDamageAssessment::orderBy('id')->pluck('amount')->all());
    }

    public function test_the_damage_route_derives_references_and_validates_the_amount(): void
    {
        [, $application, , $device, $return] = $this->returned('09170005027', '09170005028', 'DMG-005');
        $inspection = app(RentalInspectionService::class)->record($return, $this->admin, 'بررسی');
        $route = route('admin.operations.inspections.damage.store', $inspection);

        foreach ([['amount' => -5], ['amount' => 'abc'], ['amount' => 1.5], []] as $bad) {
            $this->actingAs($this->admin)->post($route, $bad)->assertSessionHasErrors('amount');
        }

        $this->actingAs($this->admin)->post($route, [
            'amount' => 90_000,
            'device_id' => 999, 'rental_application_id' => 999, 'assessed_by_user_id' => 1,
        ])->assertSessionHas('success');

        $assessment = RentalDamageAssessment::firstOrFail();
        $this->assertSame($device->id, $assessment->device_id);
        $this->assertSame($application->id, $assessment->rental_application_id);
        $this->assertSame($this->admin->id, $assessment->assessed_by_user_id);
    }

    public function test_only_staff_with_manage_operations_may_assess_damage(): void
    {
        [$customer, , , , $return] = $this->returned('09170005029', '09170005030', 'DMG-006');
        $inspection = app(RentalInspectionService::class)->record($return, $this->admin, 'بررسی');
        $route = route('admin.operations.inspections.damage.store', $inspection);

        $this->actingAs($customer->fresh())->post($route, ['amount' => 1])->assertRedirect(route('admin.login'));
        $this->actingAs($this->staffWithRole('support', '09170005031'))->post($route, ['amount' => 1])->assertForbidden();

        $this->assertSame(0, RentalDamageAssessment::count());
    }

    /**
     * Confirmed since: the customer is told the assessed amount so they can
     * pay it -- but never the expert's internal notes.
     */
    public function test_the_customer_sees_the_assessed_amount_but_never_the_expert_notes(): void
    {
        [$customer, $application, , , $return] = $this->returned('09170005032', '09170005033', 'DMG-007');
        $inspection = app(RentalInspectionService::class)->record($return, $this->admin, 'بررسی');
        app(RentalDamageAssessmentService::class)->record($inspection, $this->admin, 654_321, 'یادداشت داخلی کارشناس');

        $this->actingAs($customer)->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertSee(persian_number(number_format(654_321)))
            ->assertDontSee('یادداشت داخلی کارشناس');

        $this->actingAs($this->admin)->get(route('admin.rental-applications.show', $application))
            ->assertOk()
            ->assertSee(persian_number(number_format(654_321)));
    }

    // ── 4. Closure readiness: a report, never a closure ──────────────────

    public function test_closure_readiness_reports_facts_and_is_never_ready_while_policy_is_undecided(): void
    {
        [, $application, $reservation, , $return] = $this->returned('09170005034', '09170005035', 'CLS-001');
        $readiness = app(RentalClosureReadiness::class);

        $status = fn () => collect($readiness->check($application->refresh())['items'])->pluck('status', 'key')->all();

        $before = $status();
        $this->assertSame('satisfied', $before['customer_return']);
        $this->assertSame('missing', $before['return_inspection']);
        $this->assertSame('missing', $before['owner_return']);
        $this->assertSame('missing', $before['owner_defect_window']);
        // Superseded items (deposit, closure trigger) are gone: there is no
        // cash deposit, and closure is gated by these confirmed prerequisites.
        $this->assertSame('missing', $before['settlement']);
        $this->assertSame('missing', $before['damage_resolution']);
        $this->assertSame('missing', $before['guarantee_note']);

        app(RentalInspectionService::class)->record($return, $this->admin, 'بررسی');
        $ownerReturn = $this->operations->openOwnerReturnForReservation($reservation, $this->admin);
        $this->operations->start($ownerReturn->refresh(), $this->admin);
        $this->custody->requestReturnToOwner($ownerReturn->refresh(), $this->admin);
        $this->custody->recordReturnToOwner($ownerReturn->refresh(), $this->admin);
        $this->travel(3)->hours();

        $after = $status();
        $this->assertSame('satisfied', $after['return_inspection']);
        $this->assertSame('satisfied', $after['owner_return']);
        $this->assertSame('satisfied', $after['owner_defect_window']);

        // Still not ready, and still not closed: no damage outcome, no note
        // resolution and no owner credit yet -- and readiness never closes.
        $this->assertFalse($readiness->check($application->refresh())['ready']);
        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }

    public function test_closure_readiness_marks_owner_steps_not_applicable_for_gamepek_stock(): void
    {
        [, $application] = $this->returned('09170005036', null, 'CLS-002');

        $items = collect(app(RentalClosureReadiness::class)->check($application)['items'])->pluck('status', 'key');

        $this->assertSame('not_applicable', $items['owner_return']);
        $this->assertSame('not_applicable', $items['owner_defect_window']);
        $this->assertSame('not_applicable', $items['settlement']);
    }

    public function test_the_admin_screen_shows_closure_readiness_for_a_returned_rental(): void
    {
        [, $application] = $this->returned('09170005037', '09170005038', 'CLS-003');

        $this->actingAs($this->admin)->get(route('admin.rental-applications.show', $application))
            ->assertOk()
            ->assertSee('آمادگی برای بستن اجاره')
            ->assertSee('قاعده تعیین نشده');

        $this->assertSame(RentalApplicationState::Returned, $application->refresh()->state);
    }

    // ── 5. Early return: current behaviour, documented and pinned ────────

    public function test_an_early_return_keeps_the_remaining_dates_blocked_at_product_and_device_level(): void
    {
        [, , $reservation, $device] = $this->returned('09170005039', '09170005040', 'EAR-001');

        // The device came back before the booked range even ended.
        $this->assertTrue(now()->lessThan($reservation->end_date));
        $this->assertSame('paid', $reservation->refresh()->state->value);

        // Product level: the booked dates still block.
        $this->assertTrue(RentalReservation::overlapping(
            $reservation->product_id,
            $reservation->start_date->toDateString(),
            $reservation->end_date->toDateString(),
        )->blocking()->exists());

        // Device level: another rental over the same dates cannot take it.
        $other = $this->paidApplication($this->customer('09170005041'), $this->uniqueNationalCode())->reservation()->firstOrFail();
        DB::table('rental_reservations')->where('id', $other->id)->update([
            'product_id' => $device->product_id,
            'start_date' => $reservation->end_date->toDateString(),
            'end_date' => $reservation->end_date->copy()->addDay()->toDateString(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->operations->attachDevice($this->operation($other->refresh(), RentalOperationType::OwnerDevicePickup), $device, $this->admin);
    }
}
