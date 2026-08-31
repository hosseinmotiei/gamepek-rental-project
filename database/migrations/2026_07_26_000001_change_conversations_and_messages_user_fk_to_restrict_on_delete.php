<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * conversations.user_id and messages.sender_id were created with ON
     * DELETE CASCADE. Every other user-referencing FK on a history-bearing
     * table (orders.user_id, payment_transactions.user_id, coupon_usages.user_id)
     * was deliberately switched to ON DELETE RESTRICT so a hard delete of a
     * User can't silently destroy their history -- support conversations are
     * the same kind of history and were missed when the messaging tables were
     * added. Switch both to ON DELETE RESTRICT to match that established
     * pattern. No user-deletion feature exists or is introduced here.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['sender_id']);
            $table->foreign('sender_id')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['sender_id']);
            $table->foreign('sender_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }
};
