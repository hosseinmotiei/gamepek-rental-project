<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheque / promissory-note guarantee for one rental application.
 *
 * The state model is complete; the RULES are not, and are deliberately not
 * invented here. Which inquiries are mandatory (TODO(business) B6), how the
 * amount is derived (B5) and the risk thresholds (B7) all live in
 * config('verification.guarantee.*') and default to empty, which means a
 * guarantee never auto-verifies -- it waits for an admin.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();

            // cheque|promissory_note|deposit_hold
            $table->string('type', 24)->default('cheque');
            $table->string('sayad_id', 16)->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->date('due_date')->nullable();
            $table->string('bank_code', 8)->nullable();
            $table->string('bank_name')->nullable();

            $table->string('state', 32)->default('pending');
            $table->boolean('ownership_match')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['rental_application_id', 'state'], 'guarantees_app_state_idx');

            // A Sayad id identifies exactly one cheque nationally, so it must
            // not be usable as a guarantee twice.
            $table->unique('sayad_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantees');
    }
};
