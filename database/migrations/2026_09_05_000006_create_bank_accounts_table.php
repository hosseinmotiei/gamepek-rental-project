<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card / IBAN ownership (stage 2).
 *
 * Replaces the frontend-only prototype in profile/index.blade.php, whose
 * lookupHolderName() was a deterministic hash into six hardcoded Persian
 * names. Ownership is now a server-side fact with its own state.
 *
 * The PAN / IBAN is encrypted; `value_hash` is an HMAC for lookup and for the
 * unique constraint, `value_mask` is what views render. The raw value never
 * reaches a Blade template and is redacted from every audit context.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // card|iban
            $table->string('type', 8);
            $table->text('value_encrypted');
            $table->char('value_hash', 64);
            $table->string('value_mask', 32);

            $table->string('owner_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('linked_iban_mask', 32)->nullable();

            $table->string('state', 32)->default('pending');
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // Application-level checks alone are not sufficient
            // (.claude/rules/database.md) -- the same card must not be
            // registrable twice for one user.
            $table->unique(['user_id', 'value_hash']);
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
