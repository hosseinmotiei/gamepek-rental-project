<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The physical promissory note's custody: received from the customer, then
 * EITHER returned to the customer OR handed to the loss-bearing owner.
 *
 * NOT MONEY. The note is a guarantee document, never a wallet balance or a
 * deposit; nothing here has an amount column on purpose.
 *
 * APPEND-ONLY, one row per event. "Held by GamePek" is derived: received and
 * no final event. The `guarantees` row (verification) is untouched.
 *
 * INVARIANTS AT THE DATABASE
 *  - each event happens at most once per guarantee: unique(guarantee_id, event)
 *  - return and transfer are mutually exclusive: both carry final_marker = 1,
 *    and unique(guarantee_id, final_marker) admits only one (NULLs repeat)
 *  - a transfer names the owner it went to; no other event names one
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_note_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('guarantee_id')->constrained('guarantees')->restrictOnDelete();
            $table->foreignId('rental_application_id')->constrained('rental_applications')->restrictOnDelete();

            // received | returned_to_customer | transferred_to_owner
            $table->string('event', 32);
            $table->unsignedTinyInteger('final_marker')->nullable();

            // Why the note went back (no_damage | damage_paid); null otherwise.
            $table->string('basis', 32)->nullable();

            // The damage the event resolves, for paid-return and transfer.
            $table->foreignId('rental_damage_assessment_id')->nullable()
                ->constrained('rental_damage_assessments')->restrictOnDelete();

            // Transfer only: the loss-bearing owner and the device concerned.
            $table->foreignId('owner_id')->nullable()->constrained('owners')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->string('notes', 1000)->nullable();

            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['guarantee_id', 'event'], 'guarantee_note_events_once_uq');
            $table->unique(['guarantee_id', 'final_marker'], 'guarantee_note_events_final_uq');
            $table->index(['owner_id', 'event']);
        });

        DB::statement("ALTER TABLE guarantee_note_events ADD CONSTRAINT guarantee_note_events_event_ck CHECK (
            (event = 'received' AND final_marker IS NULL AND owner_id IS NULL)
            OR (event = 'returned_to_customer' AND final_marker = 1 AND owner_id IS NULL AND basis IN ('no_damage', 'damage_paid'))
            OR (event = 'transferred_to_owner' AND final_marker = 1 AND owner_id IS NOT NULL AND rental_damage_assessment_id IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_note_events');
    }
};
