<?php

namespace Database\Seeders;

use App\Models\HomeSection;
use Illuminate\Database\Seeder;

/**
 * Homepage section toggles. These are structure, not content: each row
 * controls whether a section of the homepage renders and, where relevant,
 * how many items it pulls.
 *
 * The Store's PlayStation-retail sections (PSN gift cards, PS5 consoles,
 * GTA VI, PlayStation Plus, blog) are deliberately absent. Rental-specific
 * sections are added from the admin panel as `custom_*` rows, which
 * home.blade.php renders generically — no new markup or deploy required.
 */
class HomeSectionSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            ['key' => 'hero',              'title' => 'اسلایدر هیرو',        'sort_order' => 1, 'is_active' => true, 'selection_mode' => 'latest'],
            ['key' => 'quick_categories',  'title' => 'دسته‌بندی‌های سریع',  'sort_order' => 2, 'is_active' => true, 'selection_mode' => 'latest'],
            ['key' => 'features_row',      'title' => 'ردیف ویژگی‌ها',       'sort_order' => 3, 'is_active' => true, 'selection_mode' => 'latest'],
            ['key' => 'flash_sale',        'title' => 'پیشنهاد ویژه',        'sort_order' => 4, 'is_active' => true, 'selection_mode' => 'latest',      'item_limit' => 8],
            ['key' => 'best_sellers',      'title' => 'پرطرفدارترین‌ها',     'sort_order' => 5, 'is_active' => true, 'selection_mode' => 'best_seller', 'item_limit' => 8],
            ['key' => 'featured_products', 'title' => 'موارد ویژه',          'sort_order' => 6, 'is_active' => true, 'selection_mode' => 'featured',    'item_limit' => 8],
            ['key' => 'footer_features',   'title' => 'ویژگی‌های فوتر',      'sort_order' => 7, 'is_active' => true, 'selection_mode' => 'latest'],
        ];

        foreach ($sections as $data) {
            HomeSection::firstOrCreate(['key' => $data['key']], $data);
        }
    }
}
