<?php

namespace Database\Seeders;

use App\Models\TrustBadge;
use Illuminate\Database\Seeder;

class TrustBadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            ['title' => 'تحویل سریع',       'subtitle' => 'ارسال سریع',    'icon' => 'fa-solid fa-bolt',          'color_class' => 'text-yellow-400', 'location' => 'top_features',    'sort_order' => 1, 'is_active' => true],
            ['title' => 'پشتیبانی ۲۴/۷',    'subtitle' => 'همیشه کنارتیم',         'icon' => 'fa-solid fa-headset',       'color_class' => 'text-blue-400',   'location' => 'top_features',    'sort_order' => 2, 'is_active' => true],
            ['title' => 'پرداخت امن',        'subtitle' => 'درگاه معتبر بانکی',      'icon' => 'fa-solid fa-lock',          'color_class' => 'text-green-400',  'location' => 'top_features',    'sort_order' => 3, 'is_active' => true],
            ['title' => 'تضمین اصالت',       'subtitle' => 'دستگاه‌های بررسی‌شده',      'icon' => 'fa-solid fa-shield-check',  'color_class' => 'text-purple-400', 'location' => 'top_features',    'sort_order' => 4, 'is_active' => true],
            ['title' => 'قیمت رقابتی',       'subtitle' => 'بهترین قیمت بازار',     'icon' => 'fa-solid fa-tag',           'color_class' => 'text-orange-400', 'location' => 'footer_features', 'sort_order' => 1, 'is_active' => true],
            ['title' => 'اعتماد کاربران',    'subtitle' => 'پشتیبانی در تمام مراحل',      'icon' => 'fa-solid fa-star',          'color_class' => 'text-pink-400',   'location' => 'footer_features', 'sort_order' => 2, 'is_active' => true],
        ];

        foreach ($badges as $data) {
            TrustBadge::firstOrCreate(
                ['title' => $data['title'], 'location' => $data['location']],
                $data
            );
        }
    }
}
