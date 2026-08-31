<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->json('selected_options')->nullable()->after('quantity');
            // md5 of the sorted selected_options (or md5('') for a product
            // with no options) -- lets the same product exist as two
            // separate cart lines when different variants are picked,
            // while a plain no-options product still merges into one line
            // exactly as before (constant hash = same behavior as the old
            // cart_id+product_id unique constraint).
            $table->string('options_hash', 32)->default(md5(''))->after('selected_options');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id']);
            $table->unique(['cart_id', 'product_id', 'options_hash']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('selected_options')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id', 'options_hash']);
            $table->unique(['cart_id', 'product_id']);
            $table->dropColumn(['selected_options', 'options_hash']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('selected_options');
        });
    }
};
