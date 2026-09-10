<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A stable, quotable handle for one custody handover.
 *
 * WHY THIS IS NOT A RECEIPT
 *
 * The business process will eventually need custody receipts for all four legs
 * (owner -> GamePek, GamePek -> customer, customer -> GamePek, GamePek ->
 * owner). Their legal structure -- wording, signature semantics, electronic
 * validity, witness requirements, retention -- is UNSPECIFIED and requires
 * legal review (docs/business/CONFIRMED_DECISIONS.md section 4.2).
 *
 * So this migration adds the one thing that can be added without inventing any
 * of that: an internal operational reference, exactly parallel to
 * `rental_operations.operation_number`. It names a handover so it can be quoted
 * on the phone and traced through the audit log. It asserts NOTHING about legal
 * effect, acceptance, or the condition of the device. Do not build receipt
 * semantics on top of it until the legal gate is decided.
 *
 * WHY NOT REUSE THE PRIMARY KEY
 *
 * An auto-increment id leaks how many handovers have ever happened and is easy
 * to transpose when read aloud. The rest of this codebase already uses opaque
 * prefixed numbers for anything a human quotes (`application_number`,
 * `order_number`, `operation_number`); this follows that convention rather than
 * introducing a second one.
 *
 * ADDITIVE. One nullable column is added, every existing row is backfilled with
 * a generated reference, and the column is only then made NOT NULL. No existing
 * value is rewritten and no row is deleted.
 *
 * down(): drops the index and column. The references are lost, which is
 * acceptable because nothing legal or external depends on them -- that is
 * precisely the property this column was given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_custody_transfers', function (Blueprint $table) {
            $table->string('reference_number', 32)->nullable()->after('id');
        });

        // Backfill before the NOT NULL, so historical handovers get a handle
        // rather than being excluded from every report that uses one. Chunked
        // and keyed by id: no row is touched twice and none is rewritten.
        DB::table('device_custody_transfers')
            ->whereNull('reference_number')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('device_custody_transfers')
                        ->where('id', $row->id)
                        ->update([
                            'reference_number' => 'CUS-'
                                .now()->parse($row->created_at ?? now())->format('ymd')
                                .'-'.strtoupper(Str::random(6)),
                        ]);
                }
            });

        Schema::table('device_custody_transfers', function (Blueprint $table) {
            $table->unique('reference_number', 'device_custody_transfers_reference_uq');
        });

        // Raw rather than ->change(): deterministic on MariaDB and it does not
        // depend on the column definition being re-derived correctly.
        DB::statement('ALTER TABLE device_custody_transfers MODIFY reference_number VARCHAR(32) NOT NULL');
    }

    public function down(): void
    {
        Schema::table('device_custody_transfers', function (Blueprint $table) {
            $table->dropUnique('device_custody_transfers_reference_uq');
            $table->dropColumn('reference_number');
        });
    }
};
