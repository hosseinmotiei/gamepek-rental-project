<?php

namespace Tests\Feature;

use App\Enums\DeviceOwnership;
use App\Enums\DeviceState;
use App\Enums\DeviceVerificationState;
use App\Enums\OwnerState;
use App\Models\Device;
use App\Models\Order;
use App\Models\Owner;
use App\Models\User;
use App\Services\Rental\DeviceRegistrationService;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The Owner / Device domain: mixed fleet, ownership isolation, serial identity.
 *
 * PRODUCT != PHYSICAL DEVICE is the invariant under test throughout -- one
 * catalog row can have many physically distinct consoles behind it, owned by
 * different parties.
 */
class OwnerDeviceDomainTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $customer;

    private DeviceRegistrationService $devices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->devices = app(DeviceRegistrationService::class);

        $this->customer = User::create([
            'full_name' => 'کاربر عادی',
            'mobile' => '09121230001',
            'status' => 'active',
        ]);
    }

    // ── Owner ────────────────────────────────────────────────────────────

    public function test_an_unauthenticated_visitor_cannot_register_a_device(): void
    {
        $this->post(route('owner.devices.store'), [
            'product_id' => $this->makeRentableProduct(withStock: false)->id,
            'serial_number' => 'AAA111',
        ])->assertRedirect(route('auth.login'));

        $this->assertSame(0, Device::count());
    }

    public function test_a_customer_without_an_owner_profile_cannot_register_a_device(): void
    {
        $this->actingAs($this->customer)
            ->post(route('owner.devices.store'), [
                'product_id' => $this->makeRentableProduct(withStock: false)->id,
                'serial_number' => 'AAA111',
            ])
            ->assertForbidden();

        $this->assertSame(0, Device::count());
    }

    public function test_the_owner_entry_point_offers_signup_before_a_profile_exists(): void
    {
        $this->actingAs($this->customer)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('ایجاد حساب مالک', false);
    }

    public function test_a_user_can_become_an_owner_and_the_profile_starts_unverified(): void
    {
        $this->actingAs($this->customer)
            ->post(route('owner.store'), ['display_name' => 'فروشگاه تست'])
            ->assertRedirect(route('owner.dashboard'));

        $owner = $this->customer->fresh()->owner;

        $this->assertNotNull($owner);
        $this->assertSame(OwnerState::PendingVerification, $owner->state);
        $this->assertSame('فروشگاه تست', $owner->displayName());
    }

    public function test_becoming_an_owner_is_idempotent(): void
    {
        $this->devices->ensureOwnerProfile($this->customer);
        $this->devices->ensureOwnerProfile($this->customer);

        $this->assertSame(1, Owner::where('user_id', $this->customer->id)->count());
    }

    public function test_an_owner_can_register_and_list_their_own_device(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $product = $this->makeRentableProduct(withStock: false);

        $this->actingAs($this->customer->fresh())
            ->post(route('owner.devices.store'), [
                'product_id' => $product->id,
                'serial_number' => 'SN-OWNER-001',
            ])
            ->assertRedirect();

        $device = Device::firstOrFail();

        $this->assertSame($owner->id, $device->owner_id);
        $this->assertSame(DeviceOwnership::Owner, $device->ownership);
        $this->assertSame($product->id, $device->product_id);

        $this->actingAs($this->customer->fresh())
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee($product->title_fa, false);
    }

    public function test_a_suspended_owner_cannot_register_a_device(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $owner->state = OwnerState::Suspended;
        $owner->save();

        $this->actingAs($this->customer->fresh())
            ->post(route('owner.devices.store'), [
                'product_id' => $this->makeRentableProduct(withStock: false)->id,
                'serial_number' => 'SN-SUSPENDED',
            ])
            ->assertForbidden();

        $this->assertSame(0, Device::count());
    }

    // ── Device ───────────────────────────────────────────────────────────

    public function test_a_device_requires_a_product_and_a_serial(): void
    {
        $this->devices->ensureOwnerProfile($this->customer);

        $this->actingAs($this->customer->fresh())
            ->post(route('owner.devices.store'), [])
            ->assertSessionHasErrors(['product_id', 'serial_number']);

        $this->assertSame(0, Device::count());
    }

    public function test_a_device_enters_pending_review_and_is_not_verified(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $device = $this->devices->registerForOwner($owner, $this->makeRentableProduct(withStock: false), 'SN-STATE-1');

        $this->assertSame(DeviceState::PendingReview, $device->state);
        // Registering is not verifying. The two must stay distinct.
        $this->assertSame(DeviceVerificationState::Unverified, $device->verification_state);
        $this->assertFalse($device->isRentable());
        $this->assertNotNull($device->registered_at);
    }

    public function test_a_duplicate_serial_is_rejected_even_when_formatted_differently(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $product = $this->makeRentableProduct(withStock: false);

        $this->devices->registerForOwner($owner, $product, 'XK-52 991');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('دستگاهی با این شماره سریال قبلاً ثبت شده است.');

        // Same physical console, typed differently.
        $this->devices->registerForOwner($owner, $product, 'xk52991');
    }

    public function test_the_database_itself_refuses_a_duplicate_serial(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $product = $this->makeRentableProduct(withStock: false);
        $this->devices->registerForOwner($owner, $product, 'SN-UNIQUE-1');

        // Straight past the service: the unique index is the real guarantee.
        $this->expectException(QueryException::class);

        Device::insert([
            'product_id' => $product->id,
            'ownership' => 'owner',
            'owner_id' => $owner->id,
            'serial_number' => 'SN-UNIQUE-1',
            'serial_normalized' => 'SNUNIQUE1',
            'state' => 'draft',
            'verification_state' => 'unverified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_gamepek_owned_device_needs_no_owner_account(): void
    {
        $device = $this->devices->registerForGamePek($this->makeRentableProduct(withStock: false), 'SN-GP-001');

        $this->assertSame(DeviceOwnership::GamePek, $device->ownership);
        $this->assertNull($device->owner_id);
        $this->assertTrue($device->isOwnedByGamePek());
        $this->assertSame(0, Owner::count(), 'GamePek stock must not create a fake owner');
    }

    public function test_the_database_refuses_ambiguous_ownership(): void
    {
        // owner ownership with a null owner_id violates the CHECK constraint.
        $this->expectException(QueryException::class);

        Device::insert([
            'product_id' => $this->makeRentableProduct(withStock: false)->id,
            'ownership' => 'owner',
            'owner_id' => null,
            'serial_number' => 'SN-BAD',
            'serial_normalized' => 'SNBAD',
            'state' => 'draft',
            'verification_state' => 'unverified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_one_owner_can_hold_many_devices(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $product = $this->makeRentableProduct(withStock: false);

        $this->devices->registerForOwner($owner, $product, 'SN-M-1');
        $this->devices->registerForOwner($owner, $product, 'SN-M-2');
        $this->devices->registerForOwner($owner, $this->makeRentableProduct(withStock: false), 'SN-M-3');

        $this->assertSame(3, $owner->devices()->count());
    }

    public function test_one_product_can_have_many_physical_devices_from_different_owners(): void
    {
        // The whole point of PRODUCT != PHYSICAL DEVICE.
        $product = $this->makeRentableProduct(withStock: false);

        $ownerA = $this->devices->ensureOwnerProfile($this->customer);
        $userB = User::create(['full_name' => 'مالک دوم', 'mobile' => '09121230002', 'status' => 'active']);
        $ownerB = $this->devices->ensureOwnerProfile($userB);

        $this->devices->registerForOwner($ownerA, $product, 'SN-P-A');
        $this->devices->registerForOwner($ownerB, $product, 'SN-P-B');
        $this->devices->registerForGamePek($product, 'SN-P-GP');

        $this->assertSame(3, Device::where('product_id', $product->id)->count());
        $this->assertSame(1, Device::where('product_id', $product->id)->gamePekOwned()->count());
    }

    // ── Authorization / isolation ────────────────────────────────────────

    public function test_an_owner_cannot_view_another_owners_device(): void
    {
        $ownerA = $this->devices->ensureOwnerProfile($this->customer);
        $deviceA = $this->devices->registerForOwner($ownerA, $this->makeRentableProduct(withStock: false), 'SN-ISO-A');

        $userB = User::create(['full_name' => 'مالک دوم', 'mobile' => '09121230003', 'status' => 'active']);
        $this->devices->ensureOwnerProfile($userB);

        $this->actingAs($userB->fresh())
            ->get(route('owner.devices.show', $deviceA))
            ->assertForbidden();
    }

    public function test_an_owner_cannot_disable_another_owners_device(): void
    {
        $ownerA = $this->devices->ensureOwnerProfile($this->customer);
        $deviceA = $this->devices->registerForOwner($ownerA, $this->makeRentableProduct(withStock: false), 'SN-ISO-B');

        $userB = User::create(['full_name' => 'مالک دوم', 'mobile' => '09121230004', 'status' => 'active']);
        $this->devices->ensureOwnerProfile($userB);

        $this->actingAs($userB->fresh())
            ->post(route('owner.devices.disable', $deviceA))
            ->assertForbidden();

        $this->assertSame(DeviceState::PendingReview, $deviceA->fresh()->state);
    }

    public function test_no_owner_ability_reaches_a_gamepek_device(): void
    {
        $this->devices->ensureOwnerProfile($this->customer);
        $device = $this->devices->registerForGamePek($this->makeRentableProduct(withStock: false), 'SN-GP-ISO');

        $this->actingAs($this->customer->fresh())
            ->get(route('owner.devices.show', $device))
            ->assertForbidden();
    }

    public function test_an_owner_cannot_approve_their_own_device(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $device = $this->devices->registerForOwner($owner, $this->makeRentableProduct(withStock: false), 'SN-APPROVE-SELF');

        // No owner-side ability grants approval, and the admin route needs a
        // permission this account does not have.
        $this->assertFalse($this->customer->fresh()->can('approve', $device));

        // The admin route is not reachable by an owner at all -- EnsureIsAdmin
        // bounces them to the admin login before the controller runs.
        $this->actingAs($this->customer->fresh())
            ->post(route('admin.devices.approve', $device))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(DeviceState::PendingReview, $device->fresh()->state);
    }

    public function test_an_owner_cannot_reassign_ownership_or_state_by_posting_fields(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $product = $this->makeRentableProduct(withStock: false);

        $this->actingAs($this->customer->fresh())
            ->post(route('owner.devices.store'), [
                'product_id' => $product->id,
                'serial_number' => 'SN-MASS-1',
                // All of these must be ignored: not fillable.
                'ownership' => 'gamepek',
                'owner_id' => 999,
                'state' => 'approved',
                'verification_state' => 'verified',
                'approved_at' => now()->toDateTimeString(),
            ])
            ->assertRedirect();

        $device = Device::firstOrFail();

        $this->assertSame(DeviceOwnership::Owner, $device->ownership);
        $this->assertSame($owner->id, $device->owner_id);
        $this->assertSame(DeviceState::PendingReview, $device->state);
        $this->assertSame(DeviceVerificationState::Unverified, $device->verification_state);
        $this->assertNull($device->approved_at);
    }

    public function test_an_owner_cannot_change_gamepek_pricing_through_the_device(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $product = $this->makeRentableProduct(withStock: false);
        $originalPrice = $product->price;

        $this->actingAs($this->customer->fresh())
            ->post(route('owner.devices.store'), [
                'product_id' => $product->id,
                'serial_number' => 'SN-PRICE-1',
                'price' => 1,
                'daily_rate' => 1,
            ])
            ->assertRedirect();

        // GamePek sets the price; the owner has no route to it.
        $this->assertSame($originalPrice, $product->fresh()->price);
        $this->assertArrayNotHasKey('price', Device::firstOrFail()->getAttributes());
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function test_an_admin_can_inspect_owners_and_devices(): void
    {
        $this->seed(UserSeeder::class);

        $admin = User::create([
            'full_name' => 'مدیر ناوگان',
            'mobile' => '09121239999',
            'email' => 'fleet@test.local',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $admin->assignRole(Role::findByName('super_admin'));

        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $device = $this->devices->registerForOwner($owner, $this->makeRentableProduct(withStock: false), 'SN-ADMIN-1');

        $this->actingAs($admin)->get(route('admin.owners.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.owners.show', $owner))->assertOk();
        $this->actingAs($admin)->get(route('admin.devices.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.devices.show', $device))->assertOk();

        // Approval is an admin act and moves the device into the fleet.
        $this->actingAs($admin)->post(route('admin.devices.approve', $device))->assertRedirect();

        $device->refresh();
        $this->assertSame(DeviceState::Approved, $device->state);
        $this->assertSame($admin->id, $device->approved_by_user_id);
        $this->assertTrue($device->isRentable());
    }

    public function test_admin_rejection_records_a_reason(): void
    {
        $this->seed(UserSeeder::class);

        $admin = User::create([
            'full_name' => 'مدیر ناوگان',
            'mobile' => '09121239998',
            'email' => 'fleet2@test.local',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $admin->assignRole(Role::findByName('super_admin'));

        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $device = $this->devices->registerForOwner($owner, $this->makeRentableProduct(withStock: false), 'SN-REJECT-1');

        $this->actingAs($admin)
            ->post(route('admin.devices.reject', $device), ['reason' => 'سریال ناخوانا'])
            ->assertRedirect();

        $device->refresh();
        $this->assertSame(DeviceState::Rejected, $device->state);
        $this->assertSame('سریال ناخوانا', $device->rejection_reason);
        $this->assertFalse($device->isRentable());
    }

    public function test_a_plain_customer_cannot_reach_the_admin_fleet_screens(): void
    {
        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $device = $this->devices->registerForOwner($owner, $this->makeRentableProduct(withStock: false), 'SN-NOADMIN-1');

        // EnsureIsAdmin bounces a non-admin to the admin login rather than
        // returning 403. Either way the screen is unreachable; what matters is
        // that it is never rendered.
        $this->actingAs($this->customer->fresh())->get(route('admin.devices.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($this->customer->fresh())->get(route('admin.owners.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($this->customer->fresh())->get(route('admin.devices.show', $device))->assertRedirect(route('admin.login'));
    }

    // ── Disable ──────────────────────────────────────────────────────────

    public function test_an_owner_can_disable_an_approved_device_and_nothing_is_charged(): void
    {
        $this->seed(UserSeeder::class);

        $admin = User::create([
            'full_name' => 'مدیر', 'mobile' => '09121239997',
            'email' => 'fleet3@test.local', 'password' => bcrypt('password'), 'status' => 'active',
        ]);
        $admin->assignRole(Role::findByName('super_admin'));

        $owner = $this->devices->ensureOwnerProfile($this->customer);
        $device = $this->devices->registerForOwner($owner, $this->makeRentableProduct(withStock: false), 'SN-DISABLE-1');
        $this->devices->approve($device, $admin);

        $this->actingAs($this->customer->fresh())
            ->post(route('owner.devices.disable', $device), ['reason' => 'تعمیر'])
            ->assertRedirect();

        $device->refresh();
        $this->assertSame(DeviceState::Disabled, $device->state);
        $this->assertSame('تعمیر', $device->disabled_reason);
        $this->assertNotNull($device->disabled_at);

        // POLICY GATE: a penalty is understood to exist but is UNDEFINED.
        // Nothing may be charged, deducted or escalated here.
        $this->assertSame(0, Order::count());
    }

    public function test_serial_normalisation_is_conservative(): void
    {
        $this->assertSame('XK52991', Device::normalizeSerial('  xk-52 991 '));
        $this->assertSame('ABC123', Device::normalizeSerial('abc_123'));
        // Nothing beyond whitespace/separator stripping and casing.
        $this->assertSame('AB.CD/12', Device::normalizeSerial('ab.cd/12'));
    }
}
