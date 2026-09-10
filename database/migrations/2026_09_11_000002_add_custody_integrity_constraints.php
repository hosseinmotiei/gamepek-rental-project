<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level invariants for custody transfers.
 *
 * WHY AT THE DATABASE AND NOT ONLY IN THE SERVICE
 *
 * `.claude/rules/database.md`: application checks alone are not sufficient.
 * DeviceCustodyService is the only writer today, and it sets these values
 * itself -- but "the only writer today" is a fact about the current code, not
 * an invariant. A future controller, console command, import or seeder is one
 * mistake away from writing a row that says a device changed hands between the
 * wrong parties. MariaDB refuses that here regardless of what the PHP does.
 *
 * WHAT IS ENFORCED
 *
 * 1. ACTOR PAIR MATCHES TRANSFER TYPE.
 *    `owner_to_gamepek` means owner -> gamepek and nothing else. A row claiming
 *    that type while pointing customer -> owner would be a device silently
 *    changing hands.
 *
 * 2. OWNER REFERENCES MATCH THE ACTORS.
 *    An owner-side actor must name a real owner; a GamePek side must NOT, since
 *    GamePek deliberately has no owner row (see the devices migration). This
 *    stops a fake owner record being invented for GamePek stock through the
 *    custody table.
 *
 * 3. `acknowledged` REQUIRES ITS TIMESTAMP.
 *    The existing constraint already covers `transferred_at`; its sibling was
 *    missing, so a row could claim the owner confirmed a handover with no
 *    record of when.
 *
 * WHAT IS DELIBERATELY NOT ENFORCED HERE
 *
 * The operation/device agreement (an operation and its transfer naming the same
 * console) spans two tables, which a CHECK constraint cannot express in MariaDB.
 * It is asserted in DeviceCustodyService and detected after the fact by
 * OperationCustodyReconciler. A trigger was considered and rejected: a hidden
 * write-path side effect is harder to reason about than an explicit assertion
 * plus a report an operator can actually read.
 *
 * PRE-VERIFIED. Every existing row is checked before the constraints are added;
 * the migration aborts with a clear message rather than failing halfway if any
 * historical row would violate them. Nothing is rewritten to make them pass.
 *
 * down(): drops the three constraints. No data change.
 */
return new class extends Migration
{
    private const ACTORS_CK = 'device_custody_actor_pair_ck';

    private const OWNERS_CK = 'device_custody_owner_ref_ck';

    private const ACK_CK = 'device_custody_acknowledged_at_ck';

    public function up(): void
    {
        $this->assertExistingRowsComply();

        // 1. The declared type and the actual actors must agree.
        DB::statement('ALTER TABLE device_custody_transfers ADD CONSTRAINT '.self::ACTORS_CK.' CHECK ('
            ."transfer_type <> 'owner_to_gamepek' "
            ."OR (from_actor_type = 'owner' AND to_actor_type = 'gamepek')"
            .')');

        // 2. Owner-side actors name an owner; GamePek and customer sides do not.
        DB::statement('ALTER TABLE device_custody_transfers ADD CONSTRAINT '.self::OWNERS_CK.' CHECK ('
            ."((from_actor_type = 'owner' AND from_owner_id IS NOT NULL) "
            ."OR (from_actor_type <> 'owner' AND from_owner_id IS NULL)) "
            ."AND ((to_actor_type = 'owner' AND to_owner_id IS NOT NULL) "
            ."OR (to_actor_type <> 'owner' AND to_owner_id IS NULL))"
            .')');

        // 3. A confirmed handover records when it was confirmed.
        DB::statement('ALTER TABLE device_custody_transfers ADD CONSTRAINT '.self::ACK_CK.' CHECK ('
            ."state <> 'acknowledged' OR acknowledged_at IS NOT NULL"
            .')');
    }

    public function down(): void
    {
        if (! Schema::hasTable('device_custody_transfers')) {
            return;
        }

        foreach ([self::ACTORS_CK, self::OWNERS_CK, self::ACK_CK] as $constraint) {
            DB::statement('ALTER TABLE device_custody_transfers DROP CONSTRAINT '.$constraint);
        }
    }

    /**
     * Refuse to start rather than fail halfway.
     *
     * If a historical row would violate one of these, that is a real data
     * finding that a human must look at -- it is NOT something to "fix" by
     * rewriting the row, which would destroy the evidence of whatever produced
     * it (.claude/rules/database.md).
     */
    private function assertExistingRowsComply(): void
    {
        $offenders = DB::table('device_custody_transfers')
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('transfer_type', 'owner_to_gamepek')
                        ->where(fn ($q) => $q->where('from_actor_type', '<>', 'owner')
                            ->orWhere('to_actor_type', '<>', 'gamepek'));
                })->orWhere(function ($q) {
                    $q->where('from_actor_type', 'owner')->whereNull('from_owner_id');
                })->orWhere(function ($q) {
                    $q->where('from_actor_type', '<>', 'owner')->whereNotNull('from_owner_id');
                })->orWhere(function ($q) {
                    $q->where('to_actor_type', 'owner')->whereNull('to_owner_id');
                })->orWhere(function ($q) {
                    $q->where('to_actor_type', '<>', 'owner')->whereNotNull('to_owner_id');
                })->orWhere(function ($q) {
                    $q->where('state', 'acknowledged')->whereNull('acknowledged_at');
                });
            })
            ->pluck('id');

        if ($offenders->isNotEmpty()) {
            throw new RuntimeException(
                'Refusing to add custody integrity constraints: device_custody_transfers rows '
                .$offenders->implode(', ').' already violate them. Inspect these rows by hand; '
                .'do not rewrite them to make the migration pass.'
            );
        }
    }
};
