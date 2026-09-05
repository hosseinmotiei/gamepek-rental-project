<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * The rental category menu — the tree the header, the mobile menu and the
 * homepage categories section all render (MenuItem::categoryTree()).
 *
 * Two layers, deliberately:
 *
 *  - Catalog taxonomy (کرایه کنسول → Xbox / PlayStation، کرایه بازی‌های دیسکی)
 *    is created as real `categories` rows, so products can actually be filed
 *    under them and `/products?category=…` filters for real.
 *  - Informational entries (پشتیبانی، قوانین و مقررات، درباره ما) are menu
 *    items pointing at existing routes. They are navigation, not taxonomy,
 *    and no product will ever belong to them.
 *
 * Idempotent (firstOrCreate on the natural key), so re-running it never
 * duplicates a row, and it never renames or deletes what an admin has since
 * edited in پنل ادمین > منوها.
 */
class RentalNavigationSeeder extends Seeder
{
    public function run(): void
    {
        $consoleRental = Category::firstOrCreate(
            ['slug' => 'console-rental'],
            ['name_fa' => 'کرایه کنسول', 'is_active' => true, 'show_in_menu' => true, 'sort_order' => 1],
        );

        $xbox = Category::firstOrCreate(
            ['slug' => 'xbox-rental'],
            [
                'name_fa' => 'Xbox', 'name_en' => 'Xbox',
                'parent_id' => $consoleRental->id,
                'icon' => 'fa-brands fa-xbox',
                'is_active' => true, 'show_in_menu' => true, 'sort_order' => 1,
            ],
        );

        $playstation = Category::firstOrCreate(
            ['slug' => 'playstation-rental'],
            [
                'name_fa' => 'PlayStation', 'name_en' => 'PlayStation',
                'parent_id' => $consoleRental->id,
                'icon' => 'fa-brands fa-playstation',
                'is_active' => true, 'show_in_menu' => true, 'sort_order' => 2,
            ],
        );

        $discGames = Category::firstOrCreate(
            ['slug' => 'disc-games-rental'],
            [
                'name_fa' => 'کرایه بازی‌های دیسکی',
                'icon' => 'fa-solid fa-compact-disc',
                'is_active' => true, 'show_in_menu' => true, 'sort_order' => 2,
            ],
        );

        $tabs = [
            [
                'title' => 'کرایه کنسول',
                'icon' => 'fa-solid fa-gamepad',
                'type' => 'category',
                'category_id' => $consoleRental->id,
                'sort_order' => 1,
                'children' => [
                    ['title' => 'Xbox', 'icon' => 'fa-brands fa-xbox', 'type' => 'category', 'category_id' => $xbox->id],
                    ['title' => 'PlayStation', 'icon' => 'fa-brands fa-playstation', 'type' => 'category', 'category_id' => $playstation->id],
                ],
            ],
            [
                'title' => 'کرایه بازی‌های دیسکی',
                'icon' => 'fa-solid fa-compact-disc',
                'type' => 'category',
                'category_id' => $discGames->id,
                'sort_order' => 2,
                'children' => [],
            ],
            [
                'title' => 'پشتیبانی',
                'icon' => 'fa-solid fa-headset',
                'type' => 'custom_url',
                'url' => '/contact',
                'sort_order' => 3,
                'children' => [],
            ],
            [
                'title' => 'قوانین و مقررات',
                'icon' => 'fa-solid fa-file-contract',
                'type' => 'custom_url',
                'url' => '/terms',
                'sort_order' => 4,
                'children' => [],
            ],
            [
                'title' => 'درباره ما',
                'icon' => 'fa-solid fa-circle-info',
                'type' => 'custom_url',
                'url' => '/about',
                'sort_order' => 5,
                'children' => [],
            ],
        ];

        foreach ($tabs as $tab) {
            $children = $tab['children'];
            unset($tab['children']);

            $parent = MenuItem::firstOrCreate(
                ['title' => $tab['title'], 'location' => 'category_menu', 'parent_id' => null],
                $tab + ['location' => 'category_menu', 'is_active' => true],
            );

            foreach ($children as $index => $child) {
                MenuItem::firstOrCreate(
                    ['title' => $child['title'], 'location' => 'category_menu', 'parent_id' => $parent->id],
                    $child + [
                        'location' => 'category_menu',
                        'parent_id' => $parent->id,
                        'sort_order' => $index + 1,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
