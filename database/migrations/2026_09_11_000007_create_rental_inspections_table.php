<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Condition evidence recorded against a delivery or a customer return.
 *
 * WHY A TABLE, AND NOT THE HANDOVER'S OWN `notes`
 *
 * The handover note on device_custody_transfers is written once, in the same
 * transaction as the handover, by whoever recorded it. That is the right place
 * for the door check and stays exactly as it is. It cannot hold what comes
 * after: the confirmed rule that GamePek's expert determines damage (C-39)
 * implies a diagnosis that happens after receipt, possibly by someone else and
 * possibly more than once. An inspection row is that later evidence --
 * attributed, timestamped and append-only.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * No outcome, grade, severity, damage category, repair price, depreciation or
 * amount column. None of those is decided, and a column is a promise that one
 * will be filled. `findings` is free text for the same reason the handover note
 * is.
 *
 * APPEND-ONLY. No updated_at: a row is never edited. App\Models\RentalInspection
 * refuses update and delete, and nothing in the application deletes one.
 *
 * DENORMALISED REFERENCES
 *
 * reservation, application and device are copied from the operation at write
 * time and never taken from a request. They are kept on the row so the evidence
 * still names the rental and the console it was about even if an operation is
 * later examined in isolation, and so the reconciler can detect a row whose
 * references disagree with its operation.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rental_operation_id')->constrained('rental_operations')->restrictOnDelete();
            $table->foreignId('device_custody_transfer_id')->constrained('device_custody_transfers')->restrictOnDelete();
            $table->foreignId('rental_reservation_id')->constrained('rental_reservations')->restrictOnDelete();
            $table->foreignId('rental_application_id')->constrained('rental_applications')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();

            // delivery | customer_return  (App\Enums\RentalInspectionStage)
            $table->string('stage', 32);

            $table->text('findings');

            // Restrict, not null-on-delete: evidence must keep naming who
            // produced it.
            $table->foreignId('inspected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('inspected_at')->useCurrent();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['rental_operation_id', 'id']);
            $table->index(['device_id', 'id']);
        });

        DB::statement('ALTER TABLE rental_inspections ADD CONSTRAINT rental_inspections_stage_ck CHECK ('
            ."stage IN ('delivery', 'customer_return')"
            .')');
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspections');
    }
};
