<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A held date range on one product.
 *
 * `product_id` is restrictOnDelete because products soft-delete and a
 * reservation must survive one. `product_snapshot` and the quote columns are
 * snapshots taken at reservation time -- the snapshot pattern
 * .claude/rules/database.md prescribes -- so a later price change never
 * rewrites history.
 *
 * The quote always comes from RentalPricingService::quote(); the frontend
 * preview in partials/rental-panel.blade.php is never authoritative.
 * `deposit_amount` is stored but deliberately NOT part of `payable_now`: a
 * deposit is a refundable hold, not a charge.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->json('product_snapshot')->nullable();

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days');

            // Whole Toman throughout, matching RentalQuote.
            $table->unsignedBigInteger('daily_rate')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedBigInteger('rental_total')->default(0);
            $table->unsignedBigInteger('deposit_amount')->default(0);
            $table->unsignedBigInteger('payable_now')->default(0);
            $table->json('quote')->nullable();

            $table->string('state', 32)->default('draft');
            $table->timestamp('held_until')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'start_date', 'end_date'], 'rental_reservations_range_idx');
            $table->index(['state', 'held_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_reservations');
    }
};
