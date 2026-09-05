<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles and permissions — the single source of truth for admin authorization.
 *
 * Two things were fixed relative to the Store's version rather than inherited:
 *
 *  1. The Store checks `view_coupons`/`create_coupons`/`edit_coupons`/
 *     `delete_coupons` and the four `*_shipping_methods` permissions in its
 *     controllers, but never created any of them here. They appeared to work
 *     only because AppServiceProvider's `Gate::before` grants everything to
 *     super_admin/admin — every other role got a hard 403 and lost the sidebar
 *     links. All eight are now declared and assigned.
 *
 *  2. Store-only permissions (digital codes, blog, reviews, questions,
 *     waitlist and their reports) are gone, because the features are gone.
 *
 * Which roles may reach the admin panel at all is decided by
 * config('rental.admin.roles'), not here.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // ── Roles ──────────────────────────────────────────────────────────────
        $roleNames = ['super_admin', 'admin', 'order_manager', 'product_manager', 'content_manager', 'support'];
        $roles = [];
        foreach ($roleNames as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // ── Permissions ────────────────────────────────────────────────────────
        $permissionNames = [
            // Admin access
            'access_admin', 'manage_admin', 'view_dashboard',
            // Catalog items
            'view_products', 'create_products', 'edit_products', 'delete_products',
            'manage_inventory', 'manage_product_images',
            // Categories
            'view_categories', 'create_categories', 'edit_categories', 'delete_categories',
            // Orders
            'view_orders', 'edit_orders', 'update_order_status', 'cancel_orders', 'refund_orders',
            'view_order_notes', 'create_order_notes', 'print_invoice',
            // Payments
            'view_payments', 'edit_payments', 'mark_payment_status',
            // Users
            'view_users', 'edit_users', 'block_users', 'view_user_orders',
            // Coupons (checked by Admin\CouponController)
            'view_coupons', 'create_coupons', 'edit_coupons', 'delete_coupons',
            // Shipping methods (checked by Admin\ShippingMethodController)
            'view_shipping_methods', 'create_shipping_methods', 'edit_shipping_methods', 'delete_shipping_methods',
            // Settings
            'manage_settings', 'manage_theme_settings',
            'manage_seo_settings', 'manage_notification_settings',
            // Content management
            'manage_banners', 'view_banners',
            'manage_home_sections', 'view_home_sections',
            'manage_quick_categories',
            'manage_trust_badges',
            'manage_menus',
            // Reports
            'view_reports', 'export_reports',
            'view_sales_reports', 'view_order_reports', 'view_product_reports',
            'view_category_reports', 'view_user_reports', 'view_low_stock_reports',
            // Activity logs
            'view_activity_logs',
            // Messages (customer support)
            'view_messages', 'reply_messages', 'close_conversations',
            // Rental chain (stages 1-3): identity, bank ownership, guarantees,
            // contracts, applications, audit trail and SMS.
            'view_verifications', 'manage_verifications',
            'view_guarantees', 'manage_guarantees',
            'view_contracts', 'manage_contracts',
            'view_rental_applications', 'manage_rental_applications',
            'view_audit_events', 'view_sms', 'refund_payments',
        ];
        $permissions = [];
        foreach ($permissionNames as $name) {
            $permissions[$name] = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $pick = fn (array $names) => array_map(fn ($n) => $permissions[$n], $names);

        // super_admin gets everything
        $roles['super_admin']->syncPermissions(array_values($permissions));

        // admin: everything except manage_admin
        $roles['admin']->syncPermissions(
            array_values(array_diff_key($permissions, ['manage_admin' => null]))
        );

        $roles['product_manager']->syncPermissions($pick([
            'access_admin', 'view_dashboard',
            'view_products', 'create_products', 'edit_products', 'manage_inventory', 'manage_product_images',
            'view_categories', 'create_categories', 'edit_categories',
            'view_reports', 'view_product_reports', 'view_category_reports', 'view_low_stock_reports',
        ]));

        $roles['order_manager']->syncPermissions($pick([
            'access_admin', 'view_dashboard',
            'view_orders', 'edit_orders', 'update_order_status', 'cancel_orders',
            'view_order_notes', 'create_order_notes', 'print_invoice',
            'view_payments',
            'view_users', 'view_user_orders',
            'view_coupons',
            'view_shipping_methods',
            'view_reports', 'view_sales_reports', 'view_order_reports', 'export_reports',
            'view_rental_applications', 'manage_rental_applications',
            'view_verifications', 'view_guarantees', 'view_contracts',
        ]));

        $roles['content_manager']->syncPermissions($pick([
            'access_admin', 'view_dashboard',
            'view_products', 'edit_products', 'manage_product_images',
            'view_categories',
            'manage_seo_settings', 'manage_notification_settings',
            'manage_banners', 'view_banners',
            'manage_home_sections', 'view_home_sections',
            'manage_quick_categories', 'manage_trust_badges', 'manage_menus',
        ]));

        $roles['support']->syncPermissions($pick([
            'access_admin', 'view_dashboard',
            'view_orders', 'view_order_notes', 'create_order_notes',
            'view_users', 'view_user_orders',
            'view_reports', 'view_order_reports', 'view_user_reports',
            'view_activity_logs',
            'view_messages', 'reply_messages', 'close_conversations',
            'view_rental_applications', 'view_contracts',
        ]));

        // ── Production admin (OTP-based, no password) ──────────────────────────
        $adminMobile = env('ADMIN_MOBILE', '09100000001');
        $admin = User::firstOrCreate(
            ['mobile' => $adminMobile],
            ['full_name' => 'مدیر اجاره گیم‌پک', 'email' => env('ADMIN_EMAIL', 'admin@gamepek.ir'), 'status' => 'active']
        );
        $admin->syncRoles(['super_admin']);

        // ── Development super-admin (email + password) ─────────────────────────
        // Local/testing only, so weak credentials can never reach production.
        if (app()->environment(['local', 'testing'])) {
            $devEmail = env('DEV_ADMIN_EMAIL', 'admin@gamepek-rental.local');
            $devPassword = env('DEV_ADMIN_PASSWORD', 'password');

            $devAdmin = User::firstOrCreate(
                ['email' => $devEmail],
                ['full_name' => 'Dev Admin', 'mobile' => '09000000000', 'password' => Hash::make($devPassword), 'status' => 'active']
            );
            if (! $devAdmin->password) {
                $devAdmin->update(['password' => Hash::make($devPassword)]);
            }
            $devAdmin->syncRoles(['super_admin']);

            // Test customer (no admin role)
            User::firstOrCreate(['mobile' => '09120000002'], [
                'full_name' => 'کاربر تست', 'email' => 'test@gamepek.ir', 'status' => 'active',
            ]);
        }
    }
}
