<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CONFIRMED: a note retained by GamePek is not the end of the story.
 *
 * A GamePek-owned device has no owner to receive the note, so an unpaid damage
 * leaves the note with GamePek. The customer may still pay the assessed amount
 * afterwards, and once they do, the physical note goes back to them.
 *
 * `retained_by_gamepek` therefore stops being a FINAL note event. It keeps its
 * own row and its once-per-guarantee unique index, but it releases
 * `final_marker`, which exists only to make "returned to the customer" and
 * "handed to the owner" mutually exclusive
 * (unique(guarantee_id, final_marker)). With the marker freed, a retained note
 * can later be returned -- and still never transferred as well.
 *
 * No event row is added, removed or re-dated: this only corrects a marker that
 * encoded the wrong rule.
 *
 * down(): restores the marker, and refuses if a retained note has since been
 * returned -- those two rows cannot both carry final_marker = 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE guarantee_note_events DROP CONSTRAINT guarantee_note_events_event_ck');

        DB::table('guarantee_note_events')
            ->where('event', 'retained_by_gamepek')
            ->update(['final_marker' => null]);

        DB::statement($this->eventCheck(retainedIsFinal: false));
    }

    public function down(): void
    {
        $conflicted = DB::table('guarantee_note_events as retained')
            ->where('retained.event', 'retained_by_gamepek')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('guarantee_note_events as later')
                ->whereColumn('later.guarantee_id', 'retained.guarantee_id')
                ->whereNotNull('later.final_marker'))
            ->exists();

        if ($conflicted) {
            throw new RuntimeException('Refusing to roll back: a retained note has already been resolved further.');
        }

        DB::statement('ALTER TABLE guarantee_note_events DROP CONSTRAINT guarantee_note_events_event_ck');

        DB::table('guarantee_note_events')
            ->where('event', 'retained_by_gamepek')
            ->update(['final_marker' => 1]);

        DB::statement($this->eventCheck(retainedIsFinal: true));
    }

    private function eventCheck(bool $retainedIsFinal): string
    {
        $marker = $retainedIsFinal ? 'final_marker = 1' : 'final_marker IS NULL';

        return "ALTER TABLE guarantee_note_events ADD CONSTRAINT guarantee_note_events_event_ck CHECK (
            (event = 'received' AND final_marker IS NULL AND owner_id IS NULL)
            OR (event = 'returned_to_customer' AND final_marker = 1 AND owner_id IS NULL AND basis IN ('no_damage', 'damage_paid'))
            OR (event = 'transferred_to_owner' AND final_marker = 1 AND owner_id IS NOT NULL AND rental_damage_assessment_id IS NOT NULL)
            OR (event = 'retained_by_gamepek' AND {$marker} AND owner_id IS NULL AND rental_damage_assessment_id IS NOT NULL)
        )";
    }
};
