<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * Navigation menus. Only the structural, always-present links are seeded.
 *
 * The `footer_products` and `category_menu` locations are intentionally left
 * empty: their contents are catalog taxonomy, which is a rental domain
 * decision. Both are managed from پنل ادمین > منوها.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // Header
            ['title' => 'خانه',         'url' => '/',         'type' => 'custom_url', 'location' => 'header',          'sort_order' => 1, 'is_active' => true],
            ['title' => 'اجاره',        'url' => '/products', 'type' => 'custom_url', 'location' => 'header',          'sort_order' => 2, 'is_active' => true],

            // Footer — customer account
            ['title' => 'پروفایل من',   'url' => '/profile',  'type' => 'custom_url', 'location' => 'footer_customer', 'sort_order' => 1, 'is_active' => true],
            ['title' => 'سفارش‌های من', 'url' => '/orders',   'type' => 'custom_url', 'location' => 'footer_customer', 'sort_order' => 2, 'is_active' => true],
            ['title' => 'پیام‌ها',      'url' => '/messages', 'type' => 'custom_url', 'location' => 'footer_customer', 'sort_order' => 3, 'is_active' => true],
            ['title' => 'تماس با ما',   'url' => '/contact',  'type' => 'custom_url', 'location' => 'footer_customer', 'sort_order' => 4, 'is_active' => true],
            ['title' => 'قوانین و مقررات', 'url' => '/terms', 'type' => 'custom_url', 'location' => 'footer_customer', 'sort_order' => 5, 'is_active' => true],
        ];

        foreach ($items as $data) {
            MenuItem::firstOrCreate(
                ['title' => $data['title'], 'location' => $data['location']],
                $data
            );
        }
    }
}
