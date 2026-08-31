<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUG-084: orders.user_id, payment_transactions.user_id, and
     * payment_transactions.order_id all use ON DELETE CASCADE. A hard
     * delete of a User would cascade-delete every Order that user placed
     * (and every PaymentTransaction on those orders); a hard delete of an
     * Order would cascade-delete its PaymentTransaction rows directly.
     * Neither User, Order, nor PaymentTransaction uses SoftDeletes, and no
     * application call site currently hard-deletes any of the three (no
     * user-deletion feature exists), but nothing at the DB layer prevents a
     * seeder/artisan/tinker path from silently destroying financial and
     * order history. Switch all three to ON DELETE RESTRICT, mirroring the
     * pattern used for order_items.product_id (BUG-082) and
     * products.category_id (BUG-083).
     *
     * Column types and nullability are unchanged; all three columns remain
     * NOT NULL. No anonymization behavior and no user-deletion feature are
     * introduced by this migration.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')->on('orders')
                ->restrictOnDelete();

            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')->on('orders')
                ->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }
};
