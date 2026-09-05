<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secure verification media: identity photos, liveness video, handover and
 * return video.
 *
 * `disk` defaults to the private `verification` disk, never `public`. The
 * `public` disk is a REAL Apache-served directory above the Laravel root (see
 * config/filesystems.php and .claude/rules/frontend-blade.md), so anything
 * written there is reachable by URL -- identity media must never touch it.
 *
 * A purged row is KEPT with `state = purged`, `purged_at` set and `path`
 * nulled: the file is gone, but the audit trail of it having existed survives.
 *
 * down(): drops this new table only. It does NOT delete stored files -- that
 * is deliberate, so a rolled-back migration cannot destroy evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('rental_application_id')->nullable();

            // national_card|selfie|liveness_video|handover_video|return_video
            $table->string('kind', 32);
            $table->string('disk', 20)->default('verification');
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 80)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->json('metadata')->nullable();

            $table->string('state', 24)->default('uploaded');
            $table->string('rejection_reason')->nullable();

            $table->date('retention_until')->nullable();
            $table->timestamp('purged_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'kind']);
            $table->index(['state', 'retention_until'], 'verification_media_purge_idx');
            $table->index('rental_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_media');
    }
};
