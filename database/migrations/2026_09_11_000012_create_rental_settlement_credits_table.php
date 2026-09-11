<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a calculated settlement was actually credited to the owner's
 * wallet -- the line between "calculated" and "paid".
 *
 * `rental_settlements` stays immutable; crediting adds a row here pointing at
 * the exact wallet ledger entry. unique(rental_settlement_id) and
 * unique(wallet_transaction_id) make a second credit impossible even if the
 * wallet's own idempotency key were bypassed.
 *
 * APPEND-ONLY. down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_settlement_credits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rental_settlement_id')->unique()->constrained('rental_settlements')->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();

            $table->unsignedBigInteger('amount');

            $table->foreignId('credited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('credited_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE rental_settlement_credits ADD CONSTRAINT rental_settlement_credits_amount_ck CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_settlement_credits');
    }
};
