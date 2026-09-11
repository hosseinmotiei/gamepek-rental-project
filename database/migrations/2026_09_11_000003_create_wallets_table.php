<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The real Wallet backend, replacing the customer profile's `localStorage`
 * prototype (C-29: "Rental financial movement uses the Wallet" -- confirmed;
 * the ledger itself did not exist until this migration).
 *
 * One wallet per user. `balance` is NOT fillable on the model -- the same
 * single-writer discipline RentalChainOrchestrator/RentalOperationService
 * already apply: only WalletService may change it, and only inside a locked
 * transaction that also writes the ledger row that explains the change.
 *
 * `unsignedBigInteger` for balance: money in this codebase is always a plain
 * integer Toman amount (see rental_reservations' daily_rate/payable_now/etc),
 * never a decimal, and "unsigned" makes a negative balance a database-level
 * impossibility, not merely a service-level promise.
 *
 * restrictOnDelete: a user's financial history must never disappear as a side
 * effect of deleting the user row (`.claude/rules/database.md`: retention-
 * sensitive parents use restrictOnDelete, not cascadeOnDelete).
 *
 * This migration adds NO settlement, payout, deposit, refund or damage-charge
 * concept -- those remain undecided business/legal policy and are not
 * invented here. See docs/business/CONFIRMED_DECISIONS.md §4 and §1.6.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('balance')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
