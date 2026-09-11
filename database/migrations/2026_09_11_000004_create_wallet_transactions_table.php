<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable ledger behind a wallet's balance.
 *
 * Append-only, exactly like `audit_events` and `rental_application_transitions`:
 * no `updated_at`, and WalletService (the single writer) never issues an
 * UPDATE or DELETE against this table. WalletTransaction additionally
 * overrides update()/delete() to throw, so the guarantee does not rest on
 * "nothing happens to call it" alone.
 *
 * IDEMPOTENCY. `idempotency_key` is nullable and unique PER WALLET
 * (`unique(wallet_id, idempotency_key)`). MySQL/MariaDB unique indexes treat
 * multiple NULLs as distinct, so a movement made without a key is unaffected;
 * a caller that supplies one gets a database-enforced guarantee that a
 * retried request (a replayed job, a re-submitted form) can never double-move
 * money -- the same belt-and-suspenders pattern
 * `rental_operations.unique(rental_reservation_id, type)` already uses.
 *
 * `balance_after` is a snapshot, not a second source of truth: `wallets.balance`
 * remains authoritative, and this column exists so a single row is enough to
 * audit or display without recomputing a running sum.
 *
 * CHECK constraints (belt-and-suspenders, matching
 * `.claude/rules/database.md`: "application checks alone are not sufficient"):
 *   - `type` is restricted to credit/debit
 *   - `amount` is always strictly positive -- direction lives in `type`, never
 *     in the sign of `amount`
 *
 * No settlement/payout/refund/deposit/damage concept is introduced by this
 * table. `reason` is a plain string the caller supplies; the schema assumes
 * nothing about what reasons will ever exist.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            $table->string('reference_number', 32)->unique();
            $table->string('type', 16);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('reason');

            $table->string('idempotency_key')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->json('context')->nullable();

            $table->string('actor_type', 16)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['wallet_id', 'idempotency_key'], 'wallet_tx_wallet_idempotency_unique');
            $table->index(['wallet_id', 'created_at'], 'wallet_tx_wallet_created_idx');
            $table->index('correlation_id');
        });

        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_tx_type_ck CHECK (type IN ('credit', 'debit'))");
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_tx_amount_positive_ck CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
