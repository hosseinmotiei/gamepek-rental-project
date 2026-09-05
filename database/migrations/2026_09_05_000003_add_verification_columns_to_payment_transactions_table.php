<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-03 / PAY-04 / PAY-06.
 *
 * A gateway callback is an untrusted, user-reachable URL. Until now the mock
 * gateway decided success from `Status=OK` in that query string, which the
 * browser controls. Success must instead come from a server-to-server
 * verification, and the outcome of that verification needs somewhere to live:
 *
 *  - verification_state  the PaymentVerificationState enum. `unknown` is a
 *                        real answer -- Pardakht Novin documents no inquiry
 *                        operation, so status() must be able to say "I cannot
 *                        tell" without that being read as paid or unpaid.
 *  - verified_at         when the server-side check ran.
 *  - gateway_status_code the provider's own code, kept for support and for
 *                        building an error_map later.
 *  - gateway_reference   the provider's reference where it differs from the
 *                        RRN already stored in tracking_code.
 *  - reconciled_at       set by payments:reconcile (PAY-06).
 *  - correlation_id      ties the transaction to its audit_events rows.
 *
 * down(): drops only these six columns. `authority`, `tracking_code`,
 * `reversed_at` and the reverse_raw_* columns are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('verification_state', 24)->default('unverified')->after('status');
            $table->timestamp('verified_at')->nullable()->after('paid_at');
            $table->string('gateway_status_code', 32)->nullable()->after('verification_state');
            $table->string('gateway_reference', 64)->nullable()->after('tracking_code');
            $table->timestamp('reconciled_at')->nullable()->after('verified_at');
            $table->uuid('correlation_id')->nullable()->after('reconciled_at');

            $table->index('correlation_id');
            $table->index(['status', 'created_at'], 'payment_transactions_reconcile_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('payment_transactions_reconcile_idx');
            $table->dropIndex(['correlation_id']);
            $table->dropColumn([
                'verification_state',
                'verified_at',
                'gateway_status_code',
                'gateway_reference',
                'reconciled_at',
                'correlation_id',
            ]);
        });
    }
};
