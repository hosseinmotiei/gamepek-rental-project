<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Rental\RentalReservationService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The new admin screens render and are permission-gated.
 *
 * Model::shouldBeStrict() is on outside production, so a missing attribute or
 * an un-eager-loaded relation in these Blade files is a real 500 -- rendering
 * each page once is the cheapest way to catch that.
 */
class AdminRentalScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);

        $this->admin = User::create([
            'full_name' => 'مدیر آزمایشی', 'mobile' => '09121110001', 'email' => 'admin.rental@test.local', 'status' => 'active',
        ]);
        $this->admin->syncRoles(['super_admin']);

        $this->customer = User::create([
            'full_name' => 'مستأجر آزمایشی', 'mobile' => '09121110002', 'status' => 'active',
        ]);
    }

    public function test_the_rental_admin_screens_render(): void
    {
        app(IdentityVerificationService::class)->submit($this->customer, '0499370899', '1995-03-21');
        $application = app(RentalReservationService::class)->openApplication($this->customer);

        $identity = UserIdentity::where('user_id', $this->customer->id)->firstOrFail();

        $this->actingAs($this->admin);

        $this->get(route('admin.verifications.index'))->assertOk();
        $this->get(route('admin.verifications.show', $identity))->assertOk();
        $this->get(route('admin.rental-applications.index'))->assertOk();
        $this->get(route('admin.rental-applications.show', $application))->assertOk();
        $this->get(route('admin.audit-events.index'))->assertOk();
    }

    public function test_the_screens_survive_an_empty_dataset(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.verifications.index'))->assertOk()->assertSee('یافت نشد', false);
        $this->get(route('admin.rental-applications.index'))->assertOk()->assertSee('یافت نشد', false);
        $this->get(route('admin.audit-events.index'))->assertOk();
    }

    public function test_reading_an_identity_record_is_audited(): void
    {
        app(IdentityVerificationService::class)->submit($this->customer, '0499370899');
        $identity = UserIdentity::where('user_id', $this->customer->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.verifications.show', $identity))
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'identity.read',
            'resource_type' => 'UserIdentity',
            'resource_id' => $identity->id,
        ]);
    }

    /** The wallet tab builds its bank-inquiry URLs from named routes. */
    public function test_the_profile_page_renders_for_a_customer(): void
    {
        // fresh() so the model carries every column, as a real session's user does
        $this->actingAs($this->customer->fresh())->get(route('profile.index'))->assertOk();
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $manager = User::create([
            'full_name' => 'مدیر محتوا', 'mobile' => '09121110003', 'email' => 'content@test.local', 'status' => 'active',
        ]);
        $manager->syncRoles(['content_manager']);

        $application = app(RentalReservationService::class)->openApplication($this->customer);

        $this->actingAs($manager);

        $this->get(route('admin.rental-applications.index'))->assertForbidden();
        $this->get(route('admin.verifications.index'))->assertForbidden();
        $this->get(route('admin.audit-events.index'))->assertForbidden();
        $this->post(route('admin.rental-applications.approve', $application))->assertForbidden();
    }
}
