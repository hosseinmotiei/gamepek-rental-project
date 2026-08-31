<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('text_color')->default('#FFFFFF')->after('bg_gradient');
            $table->boolean('opens_in_new_tab')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['text_color', 'opens_in_new_tab']);
        });
    }
};
