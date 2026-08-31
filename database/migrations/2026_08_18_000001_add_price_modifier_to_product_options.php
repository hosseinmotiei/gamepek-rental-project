<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            // Toman, can be negative. Null/0 = this specific value doesn't
            // change the price at all (the common case for e.g. a "ظرفیت"
            // group that's purely informational).
            $table->integer('price_modifier')->default(0)->after('is_default');
        });

        // Snapshotted at add-to-cart / order-creation time (same pattern as
        // every other *_snapshot column already on order_items) -- NOT
        // recomputed live from product_option_values later, so a price
        // change or a deleted option value can never silently alter what a
        // customer already added to their cart or already paid for.
        Schema::table('cart_items', function (Blueprint $table) {
            $table->integer('options_price_modifier')->default(0)->after('selected_options');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('options_price_modifier')->default(0)->after('selected_options');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            $table->dropColumn('price_modifier');
        });
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('options_price_modifier');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('options_price_modifier');
        });
    }
};
