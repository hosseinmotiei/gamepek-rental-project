<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of every chain state change: what moved, from what, to
 * what, who caused it, and under which correlation id.
 *
 * This is the per-step audit the requirement asks for, kept next to the
 * aggregate rather than only in `audit_events`, so the chain's own history can
 * be rendered without querying the global trail.
 *
 * No `updated_at`: a transition that already happened is never edited.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();

            $table->string('from_state', 40);
            $table->string('to_state', 40);
            $table->string('reason')->nullable();

            $table->string('actor_type', 16)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->uuid('correlation_id')->nullable();
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['rental_application_id', 'created_at'], 'rental_transitions_app_idx');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_transitions');
    }
};
