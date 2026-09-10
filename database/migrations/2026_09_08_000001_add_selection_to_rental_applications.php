<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's product + date-range choice, held on the application itself.
 *
 * WHY THIS EXISTS
 *
 * Confirmed business rule C-15/C-16: a reservation happens ONLY after a
 * successful, verified payment, and there is no unpaid hold. Previously the
 * choice was stored by creating a `rental_reservations` row in state `held`
 * the moment the customer picked dates -- which blocked inventory for an
 * application that had not paid, had not verified identity, and might never
 * do either.
 *
 * The choice still has to live somewhere between selection and payment, so it
 * lives here as plain columns. No row in `rental_reservations` is created
 * until the payment clears; see RentalReservationService::materialiseAfterPayment().
 *
 * `quote` is the snapshot taken by RentalPricingService at selection time. It
 * is what the order is charged and what the eventual reservation records, so a
 * later price change never rewrites a customer's agreed figure. The frontend
 * never supplies it.
 *
 * `product_id` is restrictOnDelete: a paid application must not lose the
 * product it was for.
 *
 * down(): drops only these columns. No existing column or table is touched and
 * no row is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('order_id')
                ->constrained()->restrictOnDelete();
            $table->date('selected_start_date')->nullable()->after('product_id');
            $table->date('selected_end_date')->nullable()->after('selected_start_date');
            $table->unsignedSmallInteger('selected_days')->nullable()->after('selected_end_date');
            $table->boolean('selected_extra_controller')->default(false)->after('selected_days');
            $table->json('quote')->nullable()->after('selected_extra_controller');

            $table->index(
                ['product_id', 'selected_start_date', 'selected_end_date'],
                'rental_applications_selection_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropIndex('rental_applications_selection_idx');
            $table->dropForeign(['product_id']);
            $table->dropColumn([
                'product_id',
                'selected_start_date',
                'selected_end_date',
                'selected_days',
                'selected_extra_controller',
                'quote',
            ]);
        });
    }
};
