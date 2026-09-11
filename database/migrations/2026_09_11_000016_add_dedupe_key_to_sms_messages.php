<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One notification per event, guaranteed by the database.
 *
 * A lifecycle SMS is keyed by the event that caused it (for a state change,
 * `transition:{rental_application_transitions.id}`). The unique index makes a
 * second send for the same event impossible, whatever code path asks for it --
 * a replayed callback, a retried job, a future "resend" tool. Retrying a FAILED
 * message is unaffected: that re-dispatches the same row, it never inserts one.
 *
 * Nullable, because ad-hoc messages with no event behind them have no key, and
 * NULLs never collide in a unique index.
 *
 * down(): drops the new column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('dedupe_key', 120)->nullable()->after('template_key');
            $table->unique('dedupe_key', 'sms_messages_dedupe_key_uq');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropUnique('sms_messages_dedupe_key_uq');
            $table->dropColumn('dedupe_key');
        });
    }
};
