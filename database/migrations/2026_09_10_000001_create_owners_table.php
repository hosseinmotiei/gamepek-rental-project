<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third-party device owner's profile: the supply side of the mixed fleet.
 *
 * WHY A SATELLITE TABLE RATHER THAN A ROLE OR COLUMNS ON `users`
 *
 *  1. `users` must stay schema-compatible with the GamePek Store's, because
 *     existing user data is intended to be imported before launch
 *     (.claude/rules/database.md). Owner state cannot live there.
 *  2. Having the row IS the capability. A Spatie role would be a second source
 *     of truth for the same fact and the two would drift; CLAUDE.md's
 *     authorization note warns about exactly that. `OwnerPolicy` asks for the
 *     profile, not for a role.
 *  3. Owner verification state has to be representable independently of the
 *     customer identity chain, which is what `state` is for.
 *
 * `user_id` is unique: one owner profile per account. It is restrictOnDelete --
 * deleting a user must never silently orphan or destroy registered physical
 * devices, which are operationally and legally significant records.
 *
 * NOTE: GamePek's own devices do NOT get a row here. Ownership is an explicit
 * column on `devices`, so first-party stock needs no fake owner account.
 *
 * down(): drops this new table only. No existing table or row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();

            // What the owner is called in operational screens. Falls back to
            // the user's own name when null; not duplicated from `users`.
            $table->string('display_name')->nullable();

            $table->string('state', 32)->default('pending_verification');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            $table->timestamps();

            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
