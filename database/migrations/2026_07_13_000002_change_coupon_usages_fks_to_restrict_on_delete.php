<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * coupon_usages.coupon_id/user_id/order_id all used ON DELETE CASCADE. A hard
     * delete of a Coupon, User, or Order would silently destroy coupon_usages rows,
     * which are financial-audit-adjacent history (which discount was granted against
     * which order) — the same class of risk already addressed for orders.user_id and
     * payment_transactions.* in 2026_07_09_000002_change_order_and_payment_user_fk_to_restrict_on_delete.php,
     * but not previously extended to this table. Switch all three to ON DELETE
     * RESTRICT, matching that pattern.
     *
     * No column is nullable and no data is modified.
     */
    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->foreign('coupon_id')
                ->references('id')->on('coupons')
                ->restrictOnDelete();

            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')->on('orders')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->foreign('coupon_id')
                ->references('id')->on('coupons')
                ->cascadeOnDelete();

            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')->on('orders')
                ->cascadeOnDelete();
        });
    }
};
