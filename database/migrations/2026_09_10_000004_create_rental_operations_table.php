<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One unit of real physical work attached to a paid reservation.
 *
 * WHY A TABLE AND NOT A COLUMN ON THE RESERVATION
 *
 * A reservation is a commercial fact; an operation is a logistics fact, and
 * there will be several per reservation once delivery and return exist. Putting
 * operational state on the reservation would give the rental two state machines
 * on one row, which is the shape Phase 02 had to untangle.
 *
 * ADDITIVE ONLY. No existing table is altered and no existing row is touched.
 * Reservations that were already paid before this migration get NO operation:
 * manufacturing operational history for them would be inventing facts about
 * devices nobody has moved.
 *
 * DEVICE IS NULLABLE
 *
 * Which physical device serves a reservation is an undecided policy (see the
 * rental_reservations device_id migration). An operation therefore starts
 * without one and sits in `awaiting_device_allocation` until a human attaches
 * one explicitly. Nothing here picks a device.
 *
 * IDEMPOTENCY
 *
 * unique(rental_reservation_id, type) is the real guarantee that a replayed
 * gateway callback cannot produce a second pickup task. The service checks
 * first for a clean Persian message; the index is what holds under concurrency.
 *
 * DELETES
 *
 * Every FK is restrictOnDelete or nullOnDelete. An operational record is
 * evidence of physical custody -- it must not disappear because a catalog row,
 * an account or a device row was removed.
 *
 * down(): drops the CHECK constraint and this new table only. No data loss
 * outside it, because nothing outside it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_operations', function (Blueprint $table) {
            $table->id();

            // Human-facing handle for phone/WhatsApp coordination. Unique so it
            // can be quoted safely.
            $table->string('operation_number', 32)->unique();

            $table->foreignId('rental_reservation_id')->constrained('rental_reservations')->restrictOnDelete();

            // Denormalised deliberately: operations screens filter by
            // application constantly, and an operation must stay readable even
            // if the reservation row is later restructured.
            $table->foreignId('rental_application_id')->constrained('rental_applications')->restrictOnDelete();

            // NULL until a human attaches a concrete device. Never auto-filled.
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            // Snapshot of who the device belonged to when the task was
            // resolved. Ownership itself lives on `devices` and never changes
            // here -- this is a read convenience for operations screens.
            $table->foreignId('owner_id')->nullable()->constrained('owners')->restrictOnDelete();

            // owner_device_pickup  (App\Enums\RentalOperationType)
            $table->string('type', 40);

            // pending | awaiting_device_allocation | scheduled | in_progress |
            // completed | failed | not_required  (App\Enums\RentalOperationState)
            $table->string('state', 40)->default('pending');

            // The staff member currently responsible. Optional: unassigned work
            // is a real state in an operations queue.
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Free text written by the operator who marked the failure. No
            // taxonomy is invented, and nothing is triggered by its content --
            // refund, penalty and replacement rules are all undecided.
            $table->string('failure_reason', 500)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One pickup task per reservation, forever. This is the duplicate
            // backstop for replayed payment callbacks.
            $table->unique(['rental_reservation_id', 'type'], 'rental_operations_reservation_type_uq');

            $table->index(['state', 'type'], 'rental_operations_queue_idx');
            $table->index(['device_id', 'state']);
            $table->index(['owner_id', 'state']);
            $table->index('scheduled_at');
        });

        // Application checks alone are not sufficient (.claude/rules/database.md).
        // A completed operation without a device would mean GamePek recorded
        // receiving something it never named.
        DB::statement('ALTER TABLE rental_operations ADD CONSTRAINT rental_operations_completed_device_ck CHECK ('
            ."state <> 'completed' OR device_id IS NOT NULL"
            .')');
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_operations')) {
            DB::statement('ALTER TABLE rental_operations DROP CONSTRAINT rental_operations_completed_device_ck');
        }

        Schema::dropIfExists('rental_operations');
    }
};
