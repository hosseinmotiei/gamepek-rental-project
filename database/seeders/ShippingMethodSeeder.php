<?php

namespace Database\Seeders;

use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'title' => 'Express Delivery',
                'title_fa' => 'ارسال پیک همان روز (تهران)',
                'description' => 'تحویل در همان روز ثبت سفارش — فقط برای تهران',
                'base_cost' => 45000,
                'min_days' => 0,
                'max_days' => 0,
                'city' => 'تهران',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'title' => 'Express Post',
                'title_fa' => 'پست پیشتاز سراسری',
                'description' => '۳ تا ۵ روز کاری — سراسر کشور',
                'base_cost' => 35000,
                'min_days' => 3,
                'max_days' => 5,
                'city' => null,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'title' => 'Regular Post',
                'title_fa' => 'پست عادی',
                'description' => '۵ تا ۱۰ روز کاری — سراسر کشور',
                'base_cost' => 20000,
                'min_days' => 5,
                'max_days' => 10,
                'city' => null,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($methods as $method) {
            ShippingMethod::firstOrCreate(
                ['title' => $method['title']],
                $method
            );
        }
    }
}
