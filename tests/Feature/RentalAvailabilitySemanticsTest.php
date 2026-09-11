<?php

namespace Tests\Feature;

use App\Enums\DeviceOwnership;
use App\Enums\ReservationState;
use App\Models\Device;
use App\Models\Owner;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Rental\DeviceRegistrationService;
use App\Services\Rental\RentalAvailabilityService;
use App\Services\Rental\RentalReservationService;
use App\Support\Rental\Availability;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Phase 05A: the date semantics of the availability domain, pinned.
 *
 * Availability is answered on two independent axes and they must not be
 * confused:
 *
 *   OVERLAP  isFree()               -- is the inventory already committed?
 *   TIME     bookingBlockedReason() -- may a customer book this window at all?
 *
 * The bug this file exists to prevent is the two disagreeing. Before this
 * phase the calendar, the search form and the UI all treated a past date as
 * unbookable while the endpoint that actually writes the booking accepted it.
 *
 * Every case below is inclusive on both ends, matching how a rental is counted:
 * `end = start + days - 1`, so a one-day rental occupies only its start date.
 */
class RentalAvailabilitySemanticsTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private RentalAvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);
        $this->availability = app(RentalAvailabilityService::class);
    }

    private function customer(string $mobile): User
    {
        return User::create(['full_name' => 'مشتری', 'mobile' => $mobile, 'status' => 'active']);
    }

    private int $seq = 0;

    /**
     * A committed reservation over an explicit inclusive range.
     *
     * Written directly rather than through the payment chain: these tests are
     * about the overlap and date predicates, and driving a full KYC + payment
     * per boundary case would test the chain instead of the semantics.
     */
    private function blockingReservation(
        Product $product,
        string $start,
        string $end,
        ReservationState $state = ReservationState::Paid,
    ): RentalReservation {
        $user = User::create([
            'full_name' => 'دارنده رزرو',
            'mobile' => '0921'.str_pad((string) ++$this->seq, 7, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);

        $application = RentalApplication::create([
            'application_number' => RentalApplication::generateNumber(),
            'user_id' => $user->id,
            'submitted_at' => now(),
        ]);

        return RentalReservation::create([
            'rental_application_id' => $application->id,
            'product_id' => $product->id,
            'start_date' => $start,
            'end_date' => $end,
            'days' => 1,
            'state' => $state,
        ]);
    }

    // ── Overlap semantics: the A-E boundary cases ────────────────────────

    public static function overlapCases(): array
    {
        // Committed range is 2030-06-10 .. 2030-06-15, inclusive.
        return [
            'A exact same range' => ['2030-06-10', '2030-06-15', false],
            'B fully inside' => ['2030-06-12', '2030-06-14', false],
            'C starts on the last day' => ['2030-06-15', '2030-06-17', false],
            'D starts the day after' => ['2030-06-16', '2030-06-18', true],
            'E ends on the first day' => ['2030-06-09', '2030-06-11', false],
            'F ends the day before' => ['2030-06-07', '2030-06-09', true],
            'G one day on the boundary' => ['2030-06-10', '2030-06-10', false],
            'H one day just outside' => ['2030-06-16', '2030-06-16', true],
        ];
    }

    #[DataProvider('overlapCases')]
    public function test_overlap_is_inclusive_on_both_ends(string $start, string $end, bool $expectedFree): void
    {
        $product = $this->makeRentableProduct();
        $this->blockingReservation($product, '2030-06-10', '2030-06-15');

        $this->assertSame(
            $expectedFree,
            $this->availability->isFree($product->id, $start, $end),
            "Range {$start}..{$end} against a committed 2030-06-10..2030-06-15",
        );
    }

    public function test_adjacent_ranges_do_not_conflict(): void
    {
        $product = $this->makeRentableProduct();
        $this->blockingReservation($product, '2030-06-10', '2030-06-15');

        // Touching but not overlapping, on both sides.
        $this->assertTrue($this->availability->isFree($product->id, '2030-06-16', '2030-06-20'));
        $this->assertTrue($this->availability->isFree($product->id, '2030-06-05', '2030-06-09'));
    }

    // ── Time semantics: the axis that was missing ────────────────────────

    public function test_a_past_start_date_is_not_bookable(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $this->assertNotNull($this->availability->bookingBlockedReason($yesterday, 1));
        $this->assertFalse($this->availability->isBookableRange($yesterday, 3));

        // Persian, and it says what is wrong.
        $this->assertMatchesRegularExpression(
            '/[\x{0600}-\x{06FF}]/u',
            $this->availability->bookingBlockedReason($yesterday, 1),
        );
    }

    public function test_today_and_future_starts_are_bookable(): void
    {
        // C-03: date-based, not hourly. A rental starting today is bookable
        // all day, whatever the clock says.
        $this->assertNull($this->availability->bookingBlockedReason(now()->toDateString(), 1));
        $this->assertNull($this->availability->bookingBlockedReason(now()->addDays(30)->toDateString(), 1));
    }

    public function test_the_minimum_rental_is_one_day_and_there_is_no_maximum(): void
    {
        $future = now()->addDays(5)->toDateString();

        $this->assertNotNull($this->availability->bookingBlockedReason($future, 0));
        $this->assertNotNull($this->availability->bookingBlockedReason($future, -1));
        $this->assertNull($this->availability->bookingBlockedReason($future, 1));

        // C-05 leaves the maximum unlimited; nothing here caps it.
        $this->assertNull($this->availability->bookingBlockedReason($future, 400));
        $this->assertNull($this->availability->bookingBlockedReason($future, 5000));
    }

    public function test_the_time_rule_is_pinnable_so_it_never_depends_on_the_wall_clock(): void
    {
        $this->assertNull($this->availability->bookingBlockedReason('2030-06-10', 1, '2030-06-10'));
        $this->assertNotNull($this->availability->bookingBlockedReason('2030-06-09', 1, '2030-06-10'));
    }

    // ── The booking path now enforces the time rule ──────────────────────

    public function test_a_selection_in_the_past_is_refused_by_the_service(): void
    {
        $user = $this->customer('09220001001');
        $this->completeKyc($user, $this->uniqueNationalCode());

        $reservations = app(RentalReservationService::class);
        $application = $reservations->openApplication($user);

        try {
            $reservations->recordSelection(
                $application,
                $this->makeRentableProduct(),
                now()->subDays(10)->toDateString(),
                3,
            );
            $this->fail('A rental starting ten days ago was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $e->getMessage());
        }

        // Nothing was recorded on the application, and nothing was reserved.
        $this->assertNull($application->fresh()->selected_start_date);
        $this->assertSame(0, RentalReservation::count());
    }

    public function test_a_selection_in_the_past_is_refused_over_http(): void
    {
        $user = $this->customer('09220001002');
        $this->completeKyc($user, $this->uniqueNationalCode());

        $reservations = app(RentalReservationService::class);
        $application = $reservations->openApplication($user);
        $product = $this->makeRentableProduct();

        // The booking UI is JS-driven and posts JSON, which is the path that
        // returns a status rather than a redirect.
        $this->actingAs($user)
            ->postJson(route('rental.applications.reserve', $application), [
                'product_id' => $product->id,
                'start_date' => now()->subDays(3)->toDateString(),
                'days' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'تاریخ شروع نمی‌تواند در گذشته باشد.']);

        // A plain form post is refused too, with the same Persian reason.
        $this->actingAs($user)
            ->post(route('rental.applications.reserve', $application), [
                'product_id' => $product->id,
                'start_date' => now()->subDays(3)->toDateString(),
                'days' => 2,
            ])
            ->assertRedirect();

        $this->assertNull($application->fresh()->selected_start_date);
    }

    public function test_a_valid_future_selection_still_works(): void
    {
        $user = $this->customer('09220001003');
        $this->completeKyc($user, $this->uniqueNationalCode());

        $reservations = app(RentalReservationService::class);
        $application = $reservations->openApplication($user);
        $product = $this->makeRentableProduct();

        $start = now()->addDays(7)->toDateString();

        $reservations->recordSelection($application, $product, $start, 1);

        $application->refresh();
        $this->assertSame($start, $application->selected_start_date->toDateString());
        // A one-day rental occupies only its start date.
        $this->assertSame($start, $application->selected_end_date->toDateString());
        $this->assertSame(1, $application->selected_days);
    }

    // ── The calendar and the authority now agree ─────────────────────────

    public function test_the_calendar_and_the_booking_rule_agree_about_past_dates(): void
    {
        $past = now()->subDays(5)->toDateString();

        // The pure calendar helper has always refused past days.
        $this->assertFalse(Availability::rangeIsFree($past, 3, []));
        $this->assertSame(Availability::KIND_PAST, Availability::dayKind($past, []));

        // The booking authority now refuses them too. Before this phase these
        // two disagreed, and the permissive one was the one that wrote data.
        $this->assertFalse($this->availability->isBookableRange($past, 3));
    }

    public function test_search_accepts_a_one_day_window(): void
    {
        $day = now()->addDays(10)->toDateString();

        // C-04 makes one day the minimum rental, and the window is inclusive
        // on both ends, so from == to is a legitimate search.
        // Asserted on the view data, not the HTML: the search bar's inline
        // JavaScript carries the same message as a string literal, so it is
        // present in the markup whether or not the server rejected anything.
        $this->get(route('products.search', ['from' => $day, 'to' => $day]))
            ->assertOk()
            ->assertViewHas('searchErrors', fn ($errors) => ! array_key_exists('dates', (array) $errors))
            ->assertViewHas('from', $day)
            ->assertViewHas('to', $day)
            // Counted inclusively, so a from == to window is one day, not zero.
            ->assertViewHas('days', 1);
    }

    public function test_the_search_window_length_is_counted_inclusively(): void
    {
        $from = now()->addDays(10)->toDateString();
        $to = now()->addDays(15)->toDateString();

        // Six days inclusive, the same count the booking path would charge for.
        $this->get(route('products.search', ['from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertViewHas('days', 6);
    }

    public function test_search_still_rejects_a_reversed_window_and_a_past_start(): void
    {
        $this->get(route('products.search', [
            'from' => now()->addDays(10)->toDateString(),
            'to' => now()->addDays(5)->toDateString(),
        ]))->assertOk()->assertSee('تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.', false);

        $this->get(route('products.search', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDays(5)->toDateString(),
        ]))->assertOk()->assertSee('تاریخ شروع نمی‌تواند در گذشته باشد.', false);
    }

    // ── Only paid reservations block ─────────────────────────────────────

    public function test_only_paid_and_active_reservations_block_inventory(): void
    {
        $product = $this->makeRentableProduct();

        // A legacy unpaid hold must not block: C-10 says no unpaid hold exists.
        $this->blockingReservation($product, '2031-01-10', '2031-01-15', ReservationState::Held);

        $this->assertTrue($this->availability->isFree($product->id, '2031-01-10', '2031-01-15'));
        $this->assertSame([], $this->availability->blockedRangesFor($product->id));
    }

    public function test_an_abandoned_selection_blocks_nothing(): void
    {
        $user = $this->customer('09220001004');
        $application = $this->reservedApplication($user, $this->uniqueNationalCode());

        // The customer chose dates and walked away without paying.
        $this->assertNotNull($application->selected_start_date);
        $this->assertSame(0, RentalReservation::count());

        $this->assertTrue($this->availability->isFree(
            $application->product_id,
            $application->selected_start_date->toDateString(),
            $application->selected_end_date->toDateString(),
        ));
    }

    // ── Device eligibility does not leak into availability ───────────────

    /**
     * SUPERSEDED premise: this used to pin "adding a device cannot free a
     * committed range" (one reservation per product). Confirmed since:
     * capacity is physical devices, so an approved second device IS a second
     * unit -- while which unit serves which booking is still a human choice.
     */
    public function test_approving_another_device_adds_capacity_for_an_overlapping_range(): void
    {
        $product = $this->makeRentableProduct();
        $devices = app(DeviceRegistrationService::class);
        $admin = User::where('mobile', '09100000001')->firstOrFail();

        $start = now()->addDays(20)->toDateString();
        $end = now()->addDays(22)->toDateString();

        $this->blockingReservation($product, $start, $end);
        $this->assertFalse($this->availability->isFree($product->id, $start, $end));

        $ownerUser = User::create(['full_name' => 'مالک', 'mobile' => '09230001001', 'status' => 'active']);
        $owner = $devices->ensureOwnerProfile($ownerUser);
        $device = $devices->registerForOwner($owner, $product, 'AVL-05A-1');

        // Registered but not approved: not a rentable unit yet.
        $this->assertFalse($this->availability->isFree($product->id, $start, $end));

        $devices->approve($device, $admin);
        $this->assertTrue($this->availability->isFree($product->id, $start, $end));
    }

    public function test_a_gamepek_owned_device_needs_no_owner_for_availability(): void
    {
        $product = $this->makeRentableProduct();
        $devices = app(DeviceRegistrationService::class);
        $admin = User::where('mobile', '09100000001')->firstOrFail();

        $device = $devices->approve(
            $devices->registerForGamePek($product, 'AVL-05A-GP', [], $admin),
            $admin,
        )->refresh();

        $this->assertNull($device->owner_id);
        $this->assertSame(DeviceOwnership::GamePek, $device->ownership);

        // Availability is answered without consulting an owner at all, so
        // first-party stock needs no fake owner account to be rentable.
        $start = now()->addDays(40)->toDateString();
        $this->assertTrue($this->availability->isFree($product->id, $start, $start));
        $this->assertSame(0, Owner::count());
    }

    // ── Centralisation ───────────────────────────────────────────────────

    public function test_the_overlap_predicate_exists_in_exactly_one_place(): void
    {
        // The catalog listing, the product calendar and the booking path must
        // resolve through the same predicate. If a second implementation
        // appears in a controller or a Blade template, this catches it.
        $roots = [app_path(), resource_path('views')];
        $offenders = [];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                // The one legitimate home of the predicate.
                if (str_contains($path, 'app/Models/RentalReservation.php')) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (preg_match('/[\'"]start_date[\'"]\s*,\s*[\'"]<=[\'"]/', $contents)
                    && preg_match('/[\'"]end_date[\'"]\s*,\s*[\'"]>=[\'"]/', $contents)) {
                    $offenders[] = $path;
                }
            }
        }

        // constrainProductQuery legitimately restates it inside the authority.
        $offenders = array_values(array_filter(
            $offenders,
            fn ($p) => ! str_contains($p, 'app/Services/Rental/RentalAvailabilityService.php'),
        ));

        $this->assertSame([], $offenders, 'A second overlap predicate appeared outside the availability authority.');
    }

    public function test_the_static_rental_blob_is_not_consulted_for_live_availability(): void
    {
        // `_rental.blocked` is seeder data. Phase 02 removed it from the live
        // path because it contradicted real reservations in both directions.
        $product = $this->makeRentableProduct();

        $attributes = $product->attributes;
        $attributes['_rental']['blocked'] = [
            ['from' => '2032-03-01', 'to' => '2032-03-30', 'kind' => 'reserved'],
        ];
        $product->update(['attributes' => $attributes]);

        $this->assertTrue($this->availability->isFree($product->id, '2032-03-10', '2032-03-12'));
        $this->assertSame([], $this->availability->blockedRangesFor($product->id));
    }

    public function test_the_product_page_calendar_reflects_real_reservations(): void
    {
        $product = $this->makeRentableProduct();
        $start = now()->addDays(15)->toDateString();
        $end = now()->addDays(17)->toDateString();

        $this->blockingReservation($product, $start, $end);

        $ranges = $this->availability->blockedRangesFor($product->id);

        $this->assertCount(1, $ranges);
        $this->assertSame($start, $ranges[0]->from);
        $this->assertSame($end, $ranges[0]->to);

        $this->get(route('products.show', $product->slug))->assertOk();
    }
}
