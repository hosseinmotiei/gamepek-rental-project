<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Rental\RentalReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The customer's own "my rental applications" list.
 *
 * Scoped through User::rentalApplications(), the same pattern
 * OrderController::index() already uses -- no policy check per row, because
 * the query itself can never surface a row that does not belong to the
 * authenticated user.
 */
class RentalApplicationsIndexTest extends TestCase
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

    public function test_an_authenticated_customer_sees_their_own_applications(): void
    {
        $application = app(RentalReservationService::class)->openApplication($this->customer);

        $this->actingAs($this->customer)
            ->get(route('rental.applications.index'))
            ->assertOk()
            ->assertSee($application->application_number)
            ->assertSee($application->state->label());
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
            ->get(route('rental.applications.index'))
            ->assertOk();

        $response->assertSee($mine->application_number);
        $response->assertDontSee($theirs->application_number);
    }

    public function test_the_empty_state_renders_when_there_are_no_applications(): void
    {
        $this->actingAs($this->customer)
            ->get(route('rental.applications.index'))
            ->assertOk()
            ->assertSee('هنوز درخواست اجاره‌ای ثبت نکرده‌اید.');
    }

    public function test_a_guest_cannot_view_the_list(): void
    {
        $this->get(route('rental.applications.index'))
            ->assertRedirect();
    }
}
