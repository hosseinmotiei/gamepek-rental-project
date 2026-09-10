<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who physically holds a device, over time.
 *
 * CUSTODY IS NOT OWNERSHIP -- the whole reason this table exists.
 *
 *   ownership lives on `devices` (owner_id, ownership) and NEVER changes here
 *   custody   is the latest row in this table for that device
 *
 * An owner who hands their console to GamePek for a rental has not sold it.
 * Storing custody as a mutable column on `devices` would have made that
 * distinction one careless update away from being lost, and would have thrown
 * away the history that operations and any future dispute depend on.
 *
 * CURRENT CUSTODY IS DERIVED, NOT STORED
 *
 * Device::currentCustody() reads the latest row whose state actually means
 * possession moved, and falls back to the ownership-implied holder when there
 * is none. There is no `devices.current_custody` column on purpose: a derived
 * value and a stored value are two places to disagree.
 *
 * NO FAKE GAMEPEK -> GAMEPEK ROW
 *
 * A GamePek-owned device is already in GamePek custody. Writing a transfer for
 * it would be recording a handover that never happened. The fallback above
 * covers it with no row at all.
 *
 * ADDITIVE ONLY. No existing table is altered, no existing row is touched, and
 * no custody history is manufactured for devices that were already in the
 * fleet -- nobody observed those handovers.
 *
 * IDEMPOTENCY
 *
 * unique(rental_operation_id) enforces one handover per pickup task. Two
 * operators pressing "received" at once therefore produce exactly one custody
 * record, whatever the application layer does.
 *
 * down(): drops the CHECK constraint and this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_custody_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();

            // The operational task this handover belongs to. Nullable in shape
            // because future custody legs (delivery, return) may be initiated
            // outside a pickup task; unique so one task can never produce two.
            $table->foreignId('rental_operation_id')->nullable()
                ->constrained('rental_operations')->restrictOnDelete();

            $table->foreignId('rental_reservation_id')->nullable()
                ->constrained('rental_reservations')->restrictOnDelete();

            // owner | gamepek | customer  (App\Enums\CustodyActor)
            $table->string('from_actor_type', 16);
            $table->string('to_actor_type', 16);

            // Populated only when the corresponding side is an owner. GamePek
            // has no owner row by design, and the customer side is not
            // implemented in this phase.
            $table->foreignId('from_owner_id')->nullable()->constrained('owners')->restrictOnDelete();
            $table->foreignId('to_owner_id')->nullable()->constrained('owners')->restrictOnDelete();

            // owner_to_gamepek  (App\Enums\CustodyTransferType)
            $table->string('transfer_type', 40);

            // requested | transferred | acknowledged  (App\Enums\CustodyTransferState)
            $table->string('state', 24)->default('requested');

            $table->timestamp('initiated_at')->nullable();

            // The moment possession actually moved. NULL while `requested`.
            $table->timestamp('transferred_at')->nullable();

            // The counterparty confirming GamePek's record. NOT a signature and
            // NOT legal acceptance -- see App\Enums\CustodyTransferState.
            $table->timestamp('acknowledged_at')->nullable();

            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transferred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            // One handover per operational task.
            $table->unique('rental_operation_id', 'device_custody_transfers_operation_uq');

            // Reading current custody is "latest row for this device".
            $table->index(['device_id', 'state', 'id'], 'device_custody_current_idx');
            $table->index(['from_owner_id', 'state']);
        });

        // Possession cannot have moved without a timestamp saying when.
        DB::statement('ALTER TABLE device_custody_transfers ADD CONSTRAINT device_custody_transferred_at_ck CHECK ('
            ."state = 'requested' OR transferred_at IS NOT NULL"
            .')');
    }

    public function down(): void
    {
        if (Schema::hasTable('device_custody_transfers')) {
            DB::statement('ALTER TABLE device_custody_transfers DROP CONSTRAINT device_custody_transferred_at_ck');
        }

        Schema::dropIfExists('device_custody_transfers');
    }
};
