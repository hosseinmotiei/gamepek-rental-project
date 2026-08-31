<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUG-082: order_items.product_id used ON DELETE CASCADE, which
     * contradicts this table's own product_*_snapshot columns (added so
     * order history survives independent of the live product row). A hard
     * delete of a product would silently destroy the order_items rows that
     * reference it. Switch to ON DELETE RESTRICT so a product referenced by
     * any order_items row cannot be hard-deleted at all — matching the
     * existing application-level guard in Admin\ProductController::destroy().
     *
     * product_id remains NOT NULL; no data is modified.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete();
        });
    }
};
