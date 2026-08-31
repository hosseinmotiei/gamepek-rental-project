<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // BUG-094: member seeders are idempotent (firstOrCreate), but an
        // accidental `php artisan db:seed` in production should still
        // require an explicit opt-in rather than silently running.
        if (app()->environment('production') && !env('ALLOW_PRODUCTION_SEED', false)) {
            $this->command->error('DatabaseSeeder is blocked in production. Set ALLOW_PRODUCTION_SEED=true to override.');
            return;
        }

        $this->call([
            UserSeeder::class,
            SettingsSeeder::class,
            ShippingMethodSeeder::class,
            HomeSectionSeeder::class,
            TrustBadgeSeeder::class,
            MenuSeeder::class,
        ]);

        // Catalog taxonomy, banners and quick-category tiles are deliberately
        // NOT seeded. They are content, and the rental taxonomy is a domain
        // decision that has not been made yet — inventing one here would bake
        // a guess into the foundation. All three are fully manageable from the
        // admin panel (دسته‌بندی‌ها / بنرها / دسته‌های سریع).
    }
}
