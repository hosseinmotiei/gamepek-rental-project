<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_categories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('icon')->nullable();        // FontAwesome class e.g. fa-gamepad
            $table->string('image')->nullable();
            $table->string('link');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('color_class')->nullable(); // Tailwind class e.g. text-brandBlue
            $table->string('bg_class')->nullable();    // e.g. bg-blue-50
            $table->boolean('highlight')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('opens_in_new_tab')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_categories');
    }
};
