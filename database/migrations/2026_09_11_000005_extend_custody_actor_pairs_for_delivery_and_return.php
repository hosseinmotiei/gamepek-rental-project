<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teaches the actor-pair invariant about the two new custody legs.
 *
 * WHY THIS IS NEEDED AT ALL
 *
 * The original `device_custody_actor_pair_ck` reads
 * `transfer_type <> 'owner_to_gamepek' OR (from = 'owner' AND to = 'gamepek')`.
 * That was exactly right while one leg existed, but it is VACUOUSLY TRUE for
 * any other transfer_type: the moment `gamepek_to_customer` became producible,
 * the database would have stopped checking actor pairs for it entirely. A
 * device could then be recorded as moving between the wrong two parties on a
 * delivery row and nothing below the service layer would object.
 *
 * WHAT IS ENFORCED NOW
 *
 * 1. Each known transfer_type must carry exactly its own actor pair:
 *      owner_to_gamepek     owner    -> gamepek
 *      gamepek_to_customer  gamepek  -> customer
 *      customer_to_gamepek  customer -> gamepek
 * 2. transfer_type must BE one of those three. This closes the vacuous branch
 *    for good: an unknown type can no longer be written at all, so a future
 *    leg cannot slip in unconstrained the way this one nearly did.
 *
 * `device_custody_owner_ref_ck` is deliberately left alone -- it already says
 * "an owner-side actor names an owner, any other side names none", which is
 * still exactly right for the new legs: gamepek and customer sides both carry
 * NULL owner references.
 *
 * PRE-VERIFIED. Existing rows are checked before the constraint is swapped;
 * the migration aborts with a clear message rather than failing halfway. No
 * row is rewritten to make it pass (.claude/rules/database.md).
 *
 * down(): restores the original single-leg constraint exactly as it was.
 */
return new class extends Migration
{
    private const ACTORS_CK = 'device_custody_actor_pair_ck';

    /** @var list<array{type: string, from: string, to: string}> */
    private const LEGS = [
        ['type' => 'owner_to_gamepek', 'from' => 'owner', 'to' => 'gamepek'],
        ['type' => 'gamepek_to_customer', 'from' => 'gamepek', 'to' => 'customer'],
        ['type' => 'customer_to_gamepek', 'from' => 'customer', 'to' => 'gamepek'],
    ];

    public function up(): void
    {
        $this->assertExistingRowsComply();

        DB::statement('ALTER TABLE device_custody_transfers DROP CONSTRAINT '.self::ACTORS_CK);
        DB::statement('ALTER TABLE device_custody_transfers ADD CONSTRAINT '.self::ACTORS_CK.' CHECK ('
            .$this->pairClauses()
            .' AND '.$this->knownTypeClause()
            .')');
    }

    public function down(): void
    {
        if (! Schema::hasTable('device_custody_transfers')) {
            return;
        }

        DB::statement('ALTER TABLE device_custody_transfers DROP CONSTRAINT '.self::ACTORS_CK);

        // The original, single-leg form.
        DB::statement('ALTER TABLE device_custody_transfers ADD CONSTRAINT '.self::ACTORS_CK.' CHECK ('
            ."transfer_type <> 'owner_to_gamepek' "
            ."OR (from_actor_type = 'owner' AND to_actor_type = 'gamepek')"
            .')');
    }

    private function pairClauses(): string
    {
        return collect(self::LEGS)
            ->map(fn (array $leg) => "(transfer_type <> '{$leg['type']}' "
                ."OR (from_actor_type = '{$leg['from']}' AND to_actor_type = '{$leg['to']}'))")
            ->implode(' AND ');
    }

    private function knownTypeClause(): string
    {
        $types = collect(self::LEGS)->map(fn (array $leg) => "'{$leg['type']}'")->implode(', ');

        return "transfer_type IN ({$types})";
    }

    /**
     * Refuse to start rather than fail halfway.
     *
     * A row that would violate the stricter constraint is a real data finding
     * for a human to look at -- it is NOT something to "fix" by rewriting the
     * row, which would destroy the evidence of whatever produced it.
     */
    private function assertExistingRowsComply(): void
    {
        $knownTypes = collect(self::LEGS)->pluck('type')->all();

        $offenders = DB::table('device_custody_transfers')
            ->where(function ($q) use ($knownTypes) {
                $q->whereNotIn('transfer_type', $knownTypes);

                foreach (self::LEGS as $leg) {
                    $q->orWhere(function ($q) use ($leg) {
                        $q->where('transfer_type', $leg['type'])
                            ->where(fn ($q) => $q->where('from_actor_type', '<>', $leg['from'])
                                ->orWhere('to_actor_type', '<>', $leg['to']));
                    });
                }
            })
            ->pluck('id');

        if ($offenders->isNotEmpty()) {
            throw new RuntimeException(
                'Refusing to tighten the custody actor-pair constraint: device_custody_transfers rows '
                .$offenders->implode(', ').' already violate it. Inspect these rows by hand; '
                .'do not rewrite them to make the migration pass.'
            );
        }
    }
};
