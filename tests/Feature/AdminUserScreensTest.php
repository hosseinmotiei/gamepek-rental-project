<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The admin user screens.
 *
 * The defect this locks down: `/admin/users/{id}` returned HTTP 500 for every
 * user because the controller counted `wishlists`, `reviews` and `questions` --
 * Store relations that were pruned out of Rental and do not exist on User.
 * Nothing covered the page, so it stayed broken.
 */
class AdminUserScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);

        $this->admin = User::create([
            'full_name' => 'مدیر آزمایشی',
            'mobile' => '09121110777',
            'email' => 'admin-screens@test.local',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $this->admin->assignRole(Role::findByName('super_admin'));
    }

    public function test_the_user_list_renders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_a_customer_detail_page_renders(): void
    {
        $customer = User::create([
            'full_name' => 'مشتری آزمایشی',
            'mobile' => '09121110888',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $customer))
            ->assertOk()
            ->assertSee($customer->full_name, false);
    }

    public function test_an_admin_detail_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $this->admin))
            ->assertOk()
            ->assertSee($this->admin->full_name, false);
    }

    public function test_a_missing_user_is_a_404_not_a_500(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.show', 999999))
            ->assertNotFound();
    }

    public function test_the_detail_page_does_not_reference_pruned_store_relations(): void
    {
        $customer = User::create([
            'full_name' => 'مشتری دوم',
            'mobile' => '09121110999',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.show', $customer))
            ->assertOk();

        // The Store features must stay out of Rental, not be reintroduced to
        // make the page work.
        $this->assertFalse(method_exists($customer, 'wishlists'));
        $this->assertFalse(method_exists($customer, 'reviews'));
        $this->assertFalse(method_exists($customer, 'questions'));

        $response->assertDontSee('wishlists_count', false);
    }
}
