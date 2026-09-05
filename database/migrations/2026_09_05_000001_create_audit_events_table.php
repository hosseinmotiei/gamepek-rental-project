<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central, append-only audit trail for the rental verification chain.
 *
 * Deliberately a NEW table rather than extra columns on `activity_logs` /
 * `user_activity_logs`:
 *
 *  - Those two are keyed to a `users` FK (`admin_id` / `user_id`). A queue
 *    job purging expired media, a scheduled reconciliation, or a provider
 *    webhook has no user at all, and NULL there already means "unauthenticated
 *    visitor" -- a system actor would be indistinguishable from an anonymous
 *    one. `actor_type` + `actor_id` (no FK) fixes that.
 *  - Neither has `result` or `correlation_id`, both of which are required here.
 *  - Both `nullOnDelete()` their actor. A compliance trail must not lose its
 *    actor when a user row is deleted -- hence no FK on `actor_id` at all.
 *  - `Admin\ActivityLogController` renders `activity_logs` as a staff-action
 *    feed. A rental application produces 10-30 chain events; mixing them in
 *    would destroy the feed that panel exists to show. `UserActivityLogService`'s
 *    own docblock states that separation as a deliberate goal.
 *
 * Both existing tables stay exactly as they are and keep being written to.
 *
 * down(): drops only this new table. No existing data is at risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Actor. No foreign key on purpose -- see the class docblock.
            $table->string('actor_type', 16)->default('system'); // user|admin|system|provider
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            $table->string('action', 80);
            $table->string('resource_type', 80);
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->string('result', 16)->default('success'); // success|failure|denied

            $table->uuid('correlation_id')->nullable();
            $table->uuid('request_id')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Never store raw national codes, PANs, IBANs or OTPs here --
            // AuditLogger redacts per config('verification.*.logging.redact').
            $table->json('context')->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['resource_type', 'resource_id', 'occurred_at'], 'audit_events_resource_idx');
            $table->index(['action', 'occurred_at'], 'audit_events_action_idx');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'audit_events_actor_idx');
            $table->index('correlation_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
