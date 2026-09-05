<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC level-2 identity, kept on a 1:1 satellite table rather than on `users`.
 *
 * Two reasons this is NOT a set of columns on `users`:
 *
 *  1. CLAUDE.md and .claude/rules/database.md require the `users` schema to
 *     stay column-for-column compatible with the GamePek Store's, because the
 *     owner will import existing user data before launch.
 *  2. Identity data is retention-sensitive. It must be purgeable independently
 *     of the account, which a satellite table makes trivial and columns on
 *     `users` would not.
 *
 * The national code is stored encrypted. `national_code_hash` is an HMAC used
 * for lookup and for detecting the same national code registered twice --
 * searching never requires decryption. `national_code_mask` is what views
 * render; the raw value never reaches a Blade template.
 *
 * down(): drops this new table only. `users` is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete: identity records are retention-sensitive and
            // must not vanish through a cascade.
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();

            $table->text('national_code_encrypted')->nullable();
            $table->char('national_code_hash', 64)->nullable()->unique();
            $table->string('national_code_mask', 24)->nullable();
            $table->date('birth_date')->nullable();

            // Names as returned by the civil registry, kept separate from the
            // self-declared users.full_name.
            $table->string('registry_first_name')->nullable();
            $table->string('registry_last_name')->nullable();
            $table->string('registry_father_name')->nullable();

            // 1 = mobile-verified (what OTP login already gives),
            // 2 = identity-verified.
            $table->unsignedTinyInteger('kyc_level')->default(1);

            $table->string('state', 32)->default('draft');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['state', 'created_at']);
            $table->index('kyc_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
