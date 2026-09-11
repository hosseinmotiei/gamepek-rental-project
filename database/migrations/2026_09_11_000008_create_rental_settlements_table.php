<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The calculated 35/65 split of one owner rental (C-26 / C-27).
 *
 * A CALCULATION, NOT A PAYMENT. Nothing in this table moves money and no
 * wallet is touched when a row is written. `status` has exactly one value,
 * `calculated`, because that is the only thing the code can honestly do today:
 * the settlement trigger, payout timing and the amount the split applies to are
 * undecided (see config('rental.settlement')). A `paid` or `settled` value
 * would be a promise the code does not keep, so it is not declared.
 *
 * One row per reservation, forever -- unique(rental_reservation_id) is the
 * duplicate-settlement backstop that holds under concurrency.
 *
 * The shares must add up to the gross exactly: money is neither created nor
 * lost in the split. Enforced by a CHECK constraint as well as the service.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_settlements', function (Blueprint $table) {
            $table->id();

            // STL-YYMMDD-XXXXXX. An operational handle, not an invoice number.
            $table->string('reference_number', 32)->unique();

            $table->foreignId('rental_reservation_id')->unique()->constrained('rental_reservations')->restrictOnDelete();
            $table->foreignId('rental_application_id')->constrained('rental_applications')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('owners')->restrictOnDelete();

            // Which amount the split was applied to (config('rental.settlement.gross_basis')).
            $table->string('gross_basis', 40);

            // Whole Toman, like every other money column in the project.
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedBigInteger('gamepek_share');
            $table->unsignedBigInteger('owner_share');

            $table->string('status', 16)->default('calculated');

            $table->uuid('correlation_id')->nullable();
            $table->foreignId('calculated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['owner_id', 'status']);
            $table->index('correlation_id');
        });

        DB::statement('ALTER TABLE rental_settlements ADD CONSTRAINT rental_settlements_sum_ck CHECK (gamepek_share + owner_share = gross_amount)');
        DB::statement('ALTER TABLE rental_settlements ADD CONSTRAINT rental_settlements_bps_ck CHECK (commission_bps <= 10000)');
        DB::statement("ALTER TABLE rental_settlements ADD CONSTRAINT rental_settlements_status_ck CHECK (status IN ('calculated'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_settlements');
    }
};
