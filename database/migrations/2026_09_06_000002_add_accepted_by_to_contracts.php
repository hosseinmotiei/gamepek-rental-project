<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CON-03: who accepted the contract.
 *
 * `accepted_at`, `accepted_ip` and `accepted_user_agent` already recorded when
 * and from where, but not by whom -- it was only derivable through the
 * application's owner. An acceptance is the customer's explicit decision, so
 * the row states it outright.
 *
 * nullOnDelete, not cascade: a deleted user must not erase the record that an
 * acceptance happened (.claude/rules/database.md, retention).
 *
 * down(): drops the column. The audit event for `contract.accepted` still
 * carries the actor, so no acceptance becomes unattributable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('accepted_by_user_id')->nullable()->after('accepted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['accepted_by_user_id']);
            $table->dropColumn('accepted_by_user_id');
        });
    }
};
