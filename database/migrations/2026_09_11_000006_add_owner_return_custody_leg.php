<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teaches the actor-pair invariant about the fourth and final custody leg:
 * GamePek handing a returned console back to its owner (C-40).
 *
 * CONSTRAINT-ONLY. No table or column is added and no row is written.
 *
 * WHAT IS ENFORCED NOW
 *
 * 1. Each known transfer_type carries exactly its own actor pair:
 *      owner_to_gamepek     owner    -> gamepek
 *      gamepek_to_customer  gamepek  -> customer
 *      customer_to_gamepek  customer -> gamepek
 *      gamepek_to_owner     gamepek  -> owner
 * 2. transfer_type must be one of those four. In particular there is no
 *    customer -> owner leg and the database refuses one: a customer never hands
 *    a device straight to its owner.
 *
 * `device_custody_owner_ref_ck` is left alone: it already requires an
 * owner-side actor to name an owner, so a gamepek_to_owner row must carry
 * to_owner_id and must not carry from_owner_id.
 *
 * PRE-VERIFIED. Existing rows are checked first and the migration aborts
 * rather than rewriting anything (.claude/rules/database.md).
 *
 * down(): restores the three-leg form from 2026_09_11_000005. It refuses if a
 * gamepek_to_owner row exists, because dropping the leg under live data would
 * leave a row the restored constraint forbids.
 */
return new class extends Migration
{
    private const ACTORS_CK = 'device_custody_actor_pair_ck';

    /** @var list<array{type: string, from: string, to: string}> */
    private const LEGS = [
        ['type' => 'owner_to_gamepek', 'from' => 'owner', 'to' => 'gamepek'],
        ['type' => 'gamepek_to_customer', 'from' => 'gamepek', 'to' => 'customer'],
        ['type' => 'customer_to_gamepek', 'from' => 'customer', 'to' => 'gamepek'],
        ['type' => 'gamepek_to_owner', 'from' => 'gamepek', 'to' => 'owner'],
    ];

    public function up(): void
    {
        $this->assertRowsComply(self::LEGS);
        $this->replaceConstraint(self::LEGS);
    }

    public function down(): void
    {
        if (! Schema::hasTable('device_custody_transfers')) {
            return;
        }

        $threeLegs = array_slice(self::LEGS, 0, 3);

        $this->assertRowsComply($threeLegs);
        $this->replaceConstraint($threeLegs);
    }

    /** @param list<array{type: string, from: string, to: string}> $legs */
    private function replaceConstraint(array $legs): void
    {
        $pairs = collect($legs)
            ->map(fn (array $leg) => "(transfer_type <> '{$leg['type']}' "
                ."OR (from_actor_type = '{$leg['from']}' AND to_actor_type = '{$leg['to']}'))")
            ->implode(' AND ');

        $types = collect($legs)->map(fn (array $leg) => "'{$leg['type']}'")->implode(', ');

        DB::statement('ALTER TABLE device_custody_transfers DROP CONSTRAINT '.self::ACTORS_CK);
        DB::statement('ALTER TABLE device_custody_transfers ADD CONSTRAINT '.self::ACTORS_CK.' CHECK ('
            .$pairs.' AND transfer_type IN ('.$types.'))');
    }

    /**
     * Refuse to start rather than fail halfway. An offending row is a data
     * finding for a human -- never something to rewrite so the migration passes.
     *
     * @param  list<array{type: string, from: string, to: string}>  $legs
     */
    private function assertRowsComply(array $legs): void
    {
        $knownTypes = collect($legs)->pluck('type')->all();

        $offenders = DB::table('device_custody_transfers')
            ->where(function ($q) use ($knownTypes, $legs) {
                $q->whereNotIn('transfer_type', $knownTypes);

                foreach ($legs as $leg) {
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
                'Refusing to change the custody actor-pair constraint: device_custody_transfers rows '
                .$offenders->implode(', ').' would violate it. Inspect these rows by hand; '
                .'do not rewrite them to make the migration pass.'
            );
        }
    }
};
