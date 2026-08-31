<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUG-086: home_sections.category_id, quick_categories.category_id,
     * menu_items.category_id, and menu_items.product_id were plain
     * unsignedBigInteger columns with no FK constraint — a hard delete of a
     * category/product left silent dangling references instead of either
     * blocking the delete or clearing the reference. All four columns are
     * nullable and every model relation (HomeSection/QuickCategory/MenuItem
     * ->category(), and the rendering paths) already tolerates a null
     * category/product, so nullOnDelete is the correct, non-breaking choice
     * here (unlike BUG-082/BUG-083's restrictOnDelete, which protects
     * NOT NULL, order-history-critical references).
     *
     * Columns remain nullable; no data is modified.
     */
    public function up(): void
    {
        Schema::table('home_sections', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')->on('categories')
                ->nullOnDelete();
        });

        Schema::table('quick_categories', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')->on('categories')
                ->nullOnDelete();
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')->on('categories')
                ->nullOnDelete();

            $table->foreign('product_id')
                ->references('id')->on('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('home_sections', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('quick_categories', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['product_id']);
        });
    }
};
