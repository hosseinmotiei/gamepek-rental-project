<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic catalog item. Deliberately carries no rental-specific columns
     * (rental price, duration, deposit, owner, availability window) — those
     * belong to the rental domain design, not to this foundation.
     *
     * Variable, item-type-specific fields live in the `attributes` JSON column
     * and in the `product_option_*` tables, so a rental item can gain its own
     * fields without a schema change to this table.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete (not cascade): a category delete must never
            // silently destroy catalog rows referenced by order history.
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            $table->string('title_fa');
            $table->string('title_en')->nullable();
            $table->string('slug')->unique();
            $table->string('sku')->unique()->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();

            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('color')->nullable();

            $table->unsignedBigInteger('price');            // Toman
            $table->unsignedBigInteger('sale_price')->nullable();

            $table->unsignedInteger('stock_quantity')->default(0);
            $table->enum('stock_status', ['in_stock', 'out_of_stock', 'coming_soon'])->default('in_stock');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->boolean('is_flash_sale')->default(false);

            $table->string('main_image')->nullable();
            $table->json('gallery_images')->nullable();
            $table->json('attributes')->nullable();         // flexible key/value

            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('sales_count')->default(0);

            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
            $table->index(['stock_status', 'is_active']);
            $table->index(['is_featured', 'is_active']);
            $table->index(['is_flash_sale', 'is_active']);
            $table->index('price');
            $table->fullText(['title_fa', 'title_en', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
