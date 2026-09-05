<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit, versioned consent for each kind of data processing the chain
 * performs (identity inquiry, bank inquiry, credit inquiry, video retention).
 *
 * Recorded rather than assumed: an inquiry against a national registry needs a
 * demonstrable basis, and .claude/rules/admin-panel.md names consent as one of
 * the things the audit seam must cover. Which consent texts exist, and their
 * legal wording, are TODO(business).
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('kind', 60);
            $table->unsignedInteger('version')->default(1);
            $table->string('text_key', 80)->nullable();

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'kind', 'version'], 'consents_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
