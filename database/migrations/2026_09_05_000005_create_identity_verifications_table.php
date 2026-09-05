<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per individual check run against an identity: Shahkar, civil
 * registry, liveness, face match.
 *
 * Each check carries its own state so the chain step is independently
 * auditable, which is what the requirement asks for -- the parent identity's
 * state is derived from these rows, never set directly.
 *
 * `raw_request` / `raw_response` are hidden on the model and retention-flagged:
 * they hold provider payloads that may contain personal data, and
 * `retention_until` prunes them separately from the pass/fail outcome.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_identity_id')->constrained()->cascadeOnDelete();

            // shahkar|civil_registry|face_match|liveness
            $table->string('type', 32);
            $table->string('provider', 40)->nullable();
            $table->string('state', 32)->default('checking');
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('provider_status_code', 40)->nullable();

            $table->uuid('request_id')->nullable();
            $table->uuid('correlation_id')->nullable();

            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->date('retention_until')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['user_identity_id', 'type', 'state'], 'identity_verifications_lookup_idx');
            $table->index('correlation_id');
            $table->index('retention_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
