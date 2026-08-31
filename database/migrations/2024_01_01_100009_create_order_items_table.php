<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_title_snapshot');
            $table->string('product_sku_snapshot')->nullable();
            // Free-form snapshot rather than an enum: the set of item types is
            // a domain decision the rental design has not made yet, and a
            // historical row must keep whatever label it was sold under.
            $table->string('product_type_snapshot', 30)->nullable();
            $table->string('product_image_snapshot')->nullable();
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('sale_price')->nullable();
            $table->unsignedBigInteger('total_price');
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
