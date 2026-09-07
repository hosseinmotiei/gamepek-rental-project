<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CON-04: the signing challenge, bound to one contract and one signer.
 *
 * WHY A NEW TABLE RATHER THAN otp_codes. `otp_codes` is deliberately kept
 * schema-compatible with the GamePek Store's so the owner can import existing
 * user data (CLAUDE.md), its `purpose` is an ENUM('login','verify'), and it
 * has no contract or application column. A signing OTP must be bound to the
 * exact contract it signs -- a code issued for one contract must not sign
 * another, and must not log anyone in. That binding cannot be expressed in a
 * table this project is not allowed to reshape.
 *
 * Only a keyed hash of the code is stored, never the digits themselves --
 * the same rule as otp_codes.
 *
 * unique(contract_id, user_id): a retry replaces the live challenge instead of
 * accumulating rows, and two concurrent requests cannot leave two valid codes.
 *
 * FKs: contract cascades (a deleted contract's challenge is meaningless);
 * user restricts, matching contract_signatures -- signing evidence must not
 * lose its signer.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_signature_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->char('code_hash', 64);
            // dateTime, not timestamp: MySQL gives the first non-nullable
            // TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP, which
            // would silently reset the expiry every time attempts is
            // incremented -- one wrong digit would expire a live challenge.
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('consumed_at')->nullable();

            $table->string('requested_ip', 45)->nullable();
            $table->uuid('correlation_id')->nullable();

            $table->timestamps();

            $table->unique(['contract_id', 'user_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signature_otps');
    }
};
