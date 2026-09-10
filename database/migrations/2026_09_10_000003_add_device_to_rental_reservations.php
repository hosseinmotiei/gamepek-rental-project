<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preparation only: the column a reservation will eventually point at.
 *
 * Phase 02 allocates reservations against the PRODUCT and locks the product row
 * to serialise competing payments. That works and is tested; this migration
 * does not change it. `device_id` stays NULL on every row written today.
 *
 * WHY ADD IT NOW AND NOT LATER
 *
 * The target model is reservation -> physical device, and adding the column
 * additively now means the later allocation change is a behaviour change in one
 * service rather than a schema change underneath live reservation data.
 *
 * WHY NOT ALLOCATE YET
 *
 * Choosing WHICH free device a paid reservation gets is a policy decision --
 * prefer GamePek stock or owner stock, rotate for fairness, favour condition,
 * balance owner earnings. That is undecided (it interacts with the 35/65 split
 * and daily settlement), and guessing it would quietly pick winners among
 * owners. Faking an allocation would be worse than leaving the column null.
 *
 * Nullable + restrictOnDelete: a reservation must never lose the device it was
 * for, and no existing row is rewritten.
 *
 * down(): drops the FK, index and column. No data loss -- the column is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_reservations', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('product_id')
                ->constrained('devices')->restrictOnDelete();

            $table->index(['device_id', 'start_date', 'end_date'], 'rental_reservations_device_range_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rental_reservations', function (Blueprint $table) {
            $table->dropIndex('rental_reservations_device_range_idx');
            $table->dropForeign(['device_id']);
            $table->dropColumn('device_id');
        });
    }
};
