<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three confirmed rules, three additive changes.
 *
 * 1. GAMEPEK WALLET. A paid damage goes to GamePek's wallet, but wallets were
 *    one-per-user and GamePek has no user row. A wallet may now be held by a
 *    user OR by a named system purpose ('gamepek') -- never both, never
 *    neither. No row is written here; WalletService creates the GamePek
 *    wallet on first use, exactly as it creates a user's.
 *
 * 2. DAMAGE RECEIPT LEDGER LINK. rental_damage_payments.wallet_transaction_id
 *    points at the GamePek credit that received the money (unique, so one
 *    ledger entry can back only one payment). Nullable only because rows
 *    written before this rule have none; the reconciler reports those.
 *
 * 3. GAMEPEK-OWNED DAMAGE. With no owner to hand it to, an unpaid-damage note
 *    stays with GamePek: a third, mutually exclusive final outcome,
 *    `retained_by_gamepek`.
 *
 * down(): refuses while a system wallet or a retained note exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('purpose', 16)->nullable()->unique()->after('user_id');
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_holder_ck CHECK ('
            .'(user_id IS NOT NULL AND purpose IS NULL) OR (user_id IS NULL AND purpose IS NOT NULL))');
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_purpose_ck CHECK (purpose IS NULL OR purpose IN ('gamepek'))");

        Schema::table('rental_damage_payments', function (Blueprint $table) {
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->after('payment_reference')
                ->constrained('wallet_transactions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE guarantee_note_events DROP CONSTRAINT guarantee_note_events_event_ck');
        DB::statement($this->eventCheck(withRetention: true));
    }

    public function down(): void
    {
        if (DB::table('wallets')->whereNotNull('purpose')->exists()
            || DB::table('guarantee_note_events')->where('event', 'retained_by_gamepek')->exists()) {
            throw new RuntimeException('Refusing to roll back: a GamePek wallet or a retained note already exists.');
        }

        DB::statement('ALTER TABLE guarantee_note_events DROP CONSTRAINT guarantee_note_events_event_ck');
        DB::statement($this->eventCheck(withRetention: false));

        Schema::table('rental_damage_payments', function (Blueprint $table) {
            $table->dropForeign(['wallet_transaction_id']);
            $table->dropUnique(['wallet_transaction_id']);
            $table->dropColumn('wallet_transaction_id');
        });

        DB::statement('ALTER TABLE wallets DROP CONSTRAINT wallets_purpose_ck');
        DB::statement('ALTER TABLE wallets DROP CONSTRAINT wallets_holder_ck');

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['purpose']);
            $table->dropColumn('purpose');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }

    private function eventCheck(bool $withRetention): string
    {
        $retention = $withRetention
            ? " OR (event = 'retained_by_gamepek' AND final_marker = 1 AND owner_id IS NULL AND rental_damage_assessment_id IS NOT NULL)"
            : '';

        return "ALTER TABLE guarantee_note_events ADD CONSTRAINT guarantee_note_events_event_ck CHECK (
            (event = 'received' AND final_marker IS NULL AND owner_id IS NULL)
            OR (event = 'returned_to_customer' AND final_marker = 1 AND owner_id IS NULL AND basis IN ('no_damage', 'damage_paid'))
            OR (event = 'transferred_to_owner' AND final_marker = 1 AND owner_id IS NOT NULL AND rental_damage_assessment_id IS NOT NULL)"
            .$retention.')';
    }
};
