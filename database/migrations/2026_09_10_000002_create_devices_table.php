<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A registered physical rental device.
 *
 * PRODUCT != PHYSICAL DEVICE. `products` is the customer-facing catalog item
 * ("PS5 Slim Digital"); a row here is one real console with one serial number.
 * One product therefore has many devices, which is the whole point: two
 * physically distinct consoles of the same model, one owned by GamePek and one
 * by a third party, are two rows pointing at the same product.
 *
 * DEVICE IS THE UNIT -- no separate `device_units` table
 *
 * A device row already carries exactly one serial number, so it already IS the
 * concrete rentable instance. A second table keyed 1:1 to this one would be
 * duplication with no invariant behind it, and would give availability two
 * places to disagree -- the same class of bug Phase 02 removed from the
 * calendar. If a future model ever needs several rentable units per registered
 * device (it does not today: one console, one serial), that table can be added
 * then, with a reason.
 *
 * OWNERSHIP
 *
 * `ownership` + nullable `owner_id`, with a CHECK constraint binding them:
 *   owner   => owner_id IS NOT NULL
 *   gamepek => owner_id IS NULL
 * GamePek stock needs no fake owner account, and no device can be ambiguous.
 *
 * SERIAL IDENTITY
 *
 * `serial_number` keeps what was typed; `serial_normalized` is the uppercased,
 * whitespace- and separator-stripped form and carries the unique index. Two
 * devices cannot share a physical identity even if one was entered as
 * "xk-52 991" and the other as "XK52991".
 *
 * DELETES
 *
 * product_id and owner_id are both restrictOnDelete. A registered physical
 * device is an operational record; it must not vanish because a catalog row or
 * an account was removed.
 *
 * down(): drops the constraint and this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            // The catalog item this physical device is an instance of. Brand,
            // model and images are NOT copied here -- they belong to the
            // product and are read through the relationship.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // gamepek | owner  (App\Enums\DeviceOwnership)
            $table->string('ownership', 16)->default('owner');
            $table->foreignId('owner_id')->nullable()->constrained('owners')->restrictOnDelete();

            $table->string('serial_number', 120);
            $table->string('serial_normalized', 120);

            // Free text on purpose: no condition taxonomy has been agreed, and
            // inventing grades would bake in a policy. POLICY GATE.
            $table->string('condition')->nullable();
            $table->text('notes')->nullable();

            // draft | pending_review | approved | rejected | disabled
            $table->string('state', 32)->default('draft');

            // Separate from `state`: registered is not verified.
            $table->string('verification_state', 32)->default('unverified');

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('rejection_reason')->nullable();

            // Recorded when an owner disables a device. A penalty is understood
            // to apply but its amount/formula is UNDEFINED -- nothing is
            // charged or escalated here. POLICY GATE.
            $table->string('disabled_reason')->nullable();

            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One physical identity, once.
            $table->unique('serial_normalized');
            $table->index(['product_id', 'state']);
            $table->index(['owner_id', 'state']);
            $table->index(['ownership', 'state']);
            $table->index(['state', 'verification_state'], 'devices_review_idx');
        });

        // Application checks alone are not sufficient (.claude/rules/database.md).
        // MariaDB 10.2.1+ / MySQL 8.0.16+ enforce CHECK, and this database
        // already relies on that for the products price check.
        DB::statement('ALTER TABLE devices ADD CONSTRAINT devices_ownership_owner_ck CHECK ('
            ."(ownership = 'owner' AND owner_id IS NOT NULL) "
            ."OR (ownership = 'gamepek' AND owner_id IS NULL)"
            .')');
    }

    public function down(): void
    {
        if (Schema::hasTable('devices')) {
            DB::statement('ALTER TABLE devices DROP CONSTRAINT devices_ownership_owner_ck');
        }

        Schema::dropIfExists('devices');
    }
};
