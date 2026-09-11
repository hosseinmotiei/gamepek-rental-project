<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment callback's lookup, indexed.
 *
 * Every gateway callback -- a PUBLIC, unauthenticated endpoint -- runs
 * `WHERE gateway = ? AND authority = ?`. Only `gateway` was indexed, and with a
 * handful of gateways that is close to a full scan of every transaction on each
 * request: slow as volume grows, and a cheap amplifier for anyone replaying the
 * callback URL.
 *
 * A plain composite index, deliberately NOT unique. Uniqueness would be the
 * stronger guarantee, but if production data already held a duplicate pair the
 * migration would fail mid-deploy. The lookup's correctness does not depend on
 * it: gateways issue unique tokens and PaymentService re-reads the row under a
 * lock before settling.
 *
 * Additive; down() drops the index only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->index(['gateway', 'authority'], 'payment_transactions_callback_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('payment_transactions_callback_lookup_idx');
        });
    }
};
