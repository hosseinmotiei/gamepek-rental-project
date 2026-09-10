<?php

namespace Tests\Feature;

use App\Enums\ReservationState;
use App\Exceptions\ReservationConflictException;
use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * One source of availability truth (C-18), and the conflict audit surviving a
 * rolled-back reservation transaction.
 *
 * The defect this locks down: the product page's calendar read a static
 * `products.attributes._rental.blocked` blob while search queried real
 * reservations. They contradicted each other in both directions -- free dates
 * shown as reserved, and paid dates shown as free.
 */
class RentalAvailabilityConsistencyTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('verification.identity.required_checks', ['shahkar', 'civil_registry']);

        $this->customer = User::create([
            'full_name' => 'مستأجر آزمایشی',
            'mobile' => '09121110002',
            'status' => 'active',
        ]);
    }

    // ── C. Availability ──────────────────────────────────────────────────

    public function test_the_static_rental_blocked_blob_no_longer_affects_availability(): void
    {
        $product = $this->makeRentableProduct();

        // Exactly the shape the seeder wrote, and the shape the calendar used
        // to render. It must now have no effect whatsoever.
        $attributes = $product->attributes;
        $attributes['_rental']['blocked'] = [
            ['from' => now()->addDays(10)->toDateString(), 'to' => now()->addDays(14)->toDateString(), 'kind' => 'reserved'],
        ];
        $product->update(['attributes' => $attributes]);

        $availability = app(RentalAvailabilityService::class);

        $this->assertTrue($availability->isFree(
            $product->id,
            now()->addDays(10)->toDateString(),
            now()->addDays(14)->toDateString(),
        ), 'a static blocked range must not block a real booking');

        $this->assertSame([], $availability->blockedRangesFor($product->id));
    }

    public function test_the_product_calendar_and_search_agree(): void
    {
        $product = $this->makeRentableProduct();
        $from = now()->addDays(20)->toDateString();
        $to = now()->addDays(23)->toDateString();

        $this->blockWithPaidReservation($product->id, $from, $to);

        // Calendar side: the same service the product page renders from.
        $ranges = app(RentalAvailabilityService::class)->blockedRangesFor($product->id);
        $this->assertCount(1, $ranges);
        $this->assertSame($from, $ranges[0]->from);
        $this->assertSame($to, $ranges[0]->to);

        // Search side: the product must be gone for that window.
        $this->get(route('products.search', ['city' => 'تهران', 'from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertDontSee($product->title_fa, false);
    }

    public function test_a_paid_reservation_blocks_and_a_non_overlapping_range_stays_free(): void
    {
        $product = $this->makeRentableProduct();
        $from = now()->addDays(30)->toDateString();
        $to = now()->addDays(33)->toDateString();

        $this->blockWithPaidReservation($product->id, $from, $to);

        $availability = app(RentalAvailabilityService::class);

        $this->assertFalse($availability->isFree($product->id, $from, $to));
        $this->assertTrue($availability->isFree(
            $product->id,
            now()->addDays(40)->toDateString(),
            now()->addDays(42)->toDateString(),
        ));
    }

    public function test_unpaid_reservation_states_do_not_block(): void
    {
        $product = $this->makeRentableProduct();
        $from = now()->addDays(50)->toDateString();
        $to = now()->addDays(52)->toDateString();

        // Legacy rows in the pre-C-16 states. They must not block.
        foreach ([ReservationState::Held, ReservationState::AwaitingPayment] as $state) {
            $application = app(RentalReservationService::class)->openApplication($this->customer);

            RentalReservation::create([
                'rental_application_id' => $application->id,
                'product_id' => $product->id,
                'start_date' => $from,
                'end_date' => $to,
                'days' => 3,
                'state' => $state,
            ]);
        }

        $this->assertTrue(
            app(RentalAvailabilityService::class)->isFree($product->id, $from, $to),
            'held / awaiting_payment must not block inventory under C-16',
        );
    }

    public function test_inclusive_date_counting_is_preserved(): void
    {
        $application = $this->reservedApplication($this->customer);

        // 3 days from the selection helper: start + 2 == end.
        $this->assertSame(3, $application->selected_days);
        $this->assertSame(
            $application->selected_start_date->copy()->addDays(2)->toDateString(),
            $application->selected_end_date->toDateString(),
        );
    }

    // ── D. Conflict audit survives rollback ──────────────────────────────

    public function test_a_selection_conflict_is_audited(): void
    {
        $product = $this->makeRentableProduct();
        $from = now()->addDays(60)->toDateString();
        $to = now()->addDays(62)->toDateString();

        $this->blockWithPaidReservation($product->id, $from, $to);

        $this->completeKyc($this->customer);
        $application = app(RentalReservationService::class)->openApplication($this->customer->fresh());

        try {
            app(RentalReservationService::class)
                ->recordSelection($application, $product, $from, 3);
            $this->fail('expected a conflict');
        } catch (ReservationConflictException) {
            // expected
        }

        $conflicts = AuditEvent::forAction('reservation.conflict')->get();

        $this->assertCount(1, $conflicts);
        $this->assertSame('denied', $conflicts->first()->result);
    }

    public function test_the_conflict_audit_survives_the_rolled_back_reservation_transaction(): void
    {
        // The regression: the audit row used to be written INSIDE the
        // transaction that then threw, so it rolled back with it and five real
        // conflicts produced zero audit rows.
        $application = $this->reservedApplication($this->customer);

        // Someone else's paid reservation lands on the same dates while this
        // application is paying.
        $this->blockWithPaidReservation(
            $application->product_id,
            $application->selected_start_date->toDateString(),
            $application->selected_end_date->toDateString(),
        );

        $order = Order::create([
            'order_number' => 'RNT-CONFLICT-'.uniqid(),
            'user_id' => $this->customer->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'subtotal' => 1, 'shipping_cost' => 0, 'total' => 1,
            'paid_at' => now(),
        ]);
        $application->update(['order_id' => $order->id]);

        try {
            app(RentalReservationService::class)->materialiseAfterPayment($application->refresh());
            $this->fail('expected a conflict');
        } catch (ReservationConflictException) {
            // expected
        }

        $conflicts = AuditEvent::forAction('reservation.conflict')->get();

        $this->assertGreaterThan(0, $conflicts->count(), 'the conflict audit must survive the rollback');
        $this->assertSame('denied', $conflicts->last()->result);

        // And no false success was recorded for the application that lost.
        $this->assertSame(
            0,
            AuditEvent::forAction('reservation.created')
                ->where('context->rental_application_id', $application->id)
                ->count(),
        );
    }

    public function test_only_one_reservation_survives_two_competing_payments(): void
    {
        $product = $this->makeRentableProduct();
        $start = now()->addDays(70)->toDateString();

        $winner = $this->applicationReadyToPay($product, $start, '09121110011');
        $loser = $this->applicationReadyToPay($product, $start, '09121110012');

        app(RentalReservationService::class)->materialiseAfterPayment($winner->refresh());

        try {
            app(RentalReservationService::class)->materialiseAfterPayment($loser->refresh());
            $this->fail('the second payment must not also reserve the same dates');
        } catch (ReservationConflictException) {
            // expected
        }

        $this->assertSame(1, RentalReservation::count());
        $this->assertNotNull($winner->refresh()->reservation);
        $this->assertNull($loser->refresh()->reservation);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function blockWithPaidReservation(int $productId, string $from, string $to): void
    {
        $user = User::create([
            'full_name' => 'مستأجر دیگر',
            'mobile' => '0912'.random_int(1000000, 9999999),
            'status' => 'active',
        ]);

        $application = app(RentalReservationService::class)->openApplication($user);

        RentalReservation::create([
            'rental_application_id' => $application->id,
            'product_id' => $productId,
            'start_date' => $from,
            'end_date' => $to,
            'days' => 3,
            'state' => ReservationState::Paid,
        ]);
    }

    /** A KYC-complete application with a selection and a settled order. */
    private function applicationReadyToPay($product, string $start, string $mobile)
    {
        $user = User::create([
            'full_name' => 'مستأجر', 'mobile' => $mobile, 'status' => 'active',
        ]);

        $this->completeKyc($user, $this->uniqueNationalCode());

        $application = app(RentalReservationService::class)->openApplication($user->fresh());
        app(RentalReservationService::class)->recordSelection($application, $product, $start, 3);

        $order = Order::create([
            'order_number' => 'RNT-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'subtotal' => 1, 'shipping_cost' => 0, 'total' => 1,
            'paid_at' => now(),
        ]);

        $application->update(['order_id' => $order->id]);

        return $application->refresh();
    }
}
