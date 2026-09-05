<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `OrderService::markAsPaid(Order $order, ?string $trackingCode)` accepted a
 * tracking code but never captured it into its DB::transaction closure, and
 * `orders` had no column to receive it either -- so the gateway reference that
 * caused an order to become paid was silently dropped on every payment.
 *
 * The authoritative store remains `payment_transactions.tracking_code`; this
 * is a denormalised copy so an order can be traced to its gateway reference
 * without a join, which is what both the admin order screen and the audit
 * trail need.
 *
 * down(): drops the column. Data-loss risk is limited to the denormalised
 * copy -- the authoritative value stays on `payment_transactions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_tracking_code', 64)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_tracking_code');
        });
    }
};
