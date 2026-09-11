<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's payment of an assessed damage amount, recorded explicitly.
 *
 * One payment per assessment (unique), for exactly the assessed amount
 * (copied server-side). The money is paid directly (outside the wallet and the
 * gateway); `payment_reference` is the external receipt handle. Where the
 * money goes afterwards is not decided, so nothing credits any wallet.
 *
 * APPEND-ONLY. down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_damage_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rental_damage_assessment_id')->unique()
                ->constrained('rental_damage_assessments')->restrictOnDelete();
            $table->foreignId('rental_application_id')->constrained('rental_applications')->restrictOnDelete();

            $table->unsignedBigInteger('amount');
            $table->string('payment_reference', 100);

            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE rental_damage_payments ADD CONSTRAINT rental_damage_payments_amount_ck CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_damage_payments');
    }
};
