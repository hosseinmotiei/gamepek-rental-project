<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Models\User;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The customer's rental entry point (rental.dashboard). Read-only: it must
 * never leak another customer's data, and "active" must mean exactly
 * "not terminal" -- the same fact RentalChainOrchestrator already enforces,
 * not a new one invented for this page.
 */
class RentalDashboardTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::create([
            'full_name' => 'مستأجر آزمایشی',
            'mobile' => '09121110001',
            'status' => 'active',
        ]);
    }

    public function test_an_authenticated_customer_sees_the_dashboard(): void
    {
        $this->actingAs($this->customer)
            ->get(route('rental.dashboard'))
            ->assertOk()
            ->assertSee($this->customer->full_name);
    }

    public function test_a_guest_cannot_view_the_dashboard(): void
    {
        $this->get(route('rental.dashboard'))->assertRedirect();
    }

    public function test_the_empty_state_renders_when_there_are_no_applications(): void
    {
        $this->actingAs($this->customer)
            ->get(route('rental.dashboard'))
            ->assertOk()
            ->assertSee('هنوز درخواست اجاره‌ای ثبت نکرده‌اید.');
    }

    public function test_the_active_application_is_shown_with_its_product_and_state(): void
    {
        $product = $this->makeRentableProduct();
        $application = app(RentalReservationService::class)->openApplication($this->customer);
        $application = app(RentalReservationService::class)->recordSelection(
            $application,
            $product,
            now()->addDays(3)->toDateString(),
            5,
        );

        $response = $this->actingAs($this->customer)->get(route('rental.dashboard'))->assertOk();

        $response->assertSee($application->application_number)
            ->assertSee($product->title_fa)
            ->assertSee($application->state->label())
            ->assertSee(route('rental.applications.show', $application), false);
    }

    public function test_a_customer_with_only_a_cancelled_application_sees_no_active_card(): void
    {
        $application = app(RentalReservationService::class)->openApplication($this->customer);
        app(RentalChainOrchestrator::class)->cancel($application, $this->customer, 'منصرف شدم');

        $this->assertSame(RentalApplicationState::Cancelled, $application->refresh()->state);

        $response = $this->actingAs($this->customer)
            ->get(route('rental.dashboard'))
            ->assertOk();

        $response->assertDontSee('درخواست جاری', false);
        $response->assertSee($application->application_number);
    }

    public function test_verification_status_reflects_no_identity_or_bank_yet(): void
    {
        $this->actingAs($this->customer)
            ->get(route('rental.dashboard'))
            ->assertOk()
            ->assertSee('ثبت نشده');
    }

    public function test_another_customers_applications_are_never_exposed(): void
    {
        $other = User::create([
            'full_name' => 'مستأجر دیگر',
            'mobile' => '09121110002',
            'status' => 'active',
        ]);

        $mine = app(RentalReservationService::class)->openApplication($this->customer);
        $theirs = app(RentalReservationService::class)->openApplication($other);

        $response = $this->actingAs($this->customer)
            ->get(route('rental.dashboard'))
            ->assertOk();

        $response->assertSee($mine->application_number);
        $response->assertDontSee($theirs->application_number);
    }

    public function test_quick_links_point_to_existing_routes(): void
    {
        $response = $this->actingAs($this->customer)->get(route('rental.dashboard'))->assertOk();

        $response->assertSee(route('rental.applications.index'), false);
        $response->assertSee(route('verification.index'), false);
    }
}
