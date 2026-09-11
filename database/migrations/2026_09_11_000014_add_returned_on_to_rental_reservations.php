<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date a customer ACTUALLY returned the device (confirmed: an early
 * return frees the physical device for its remaining days).
 *
 * The contractual range (start_date..end_date) is never touched -- pricing,
 * settlement and the contract keep reading it. This column records what the
 * customer_return handover proved, and is written only by
 * RentalOperationService when that handover completes, in the same
 * transaction. Availability blocks up to LEAST(end_date, returned_on).
 *
 * No backfill: rows returned before this change keep blocking to end_date
 * (the reconciler reports them).
 *
 * down(): drops the column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_reservations', function (Blueprint $table) {
            $table->date('returned_on')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('rental_reservations', function (Blueprint $table) {
            $table->dropColumn('returned_on');
        });
    }
};
