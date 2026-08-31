<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUG-003: nothing at the DB layer backstops BUG-001's replay-safety
     * work. `payment_transactions.authority` is always generated as a
     * unique random string at creation (PaymentService::initiateMock()),
     * so a unique index only ever rejects a genuine duplicate/replay, never
     * a legitimate row (MySQL/MariaDB unique indexes also permit multiple
     * NULLs, so the column's existing nullability is unaffected).
     * `coupon_usages` should have at most one row per (coupon_id, order_id)
     * — OrderService::markAsPaid() only ever creates one per order — so a
     * composite unique index backstops that invariant against a race
     * between two concurrent paid-webhook/admin-mark-paid calls for the
     * same order.
     *
     * No data is modified. Both columns/relationships keep their existing
     * nullability and FK behavior.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unique('authority');
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->unique(['coupon_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique(['authority']);
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropUnique(['coupon_id', 'order_id']);
        });
    }
};
