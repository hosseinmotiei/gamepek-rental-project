<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CON-01/06: one generated contract per rental application.
 *
 * `template_key` and `template_version` are snapshotted alongside the FK so
 * the contract still identifies its source after any template change, and
 * `rendered_html` is the immutable text the customer actually accepted -- a
 * later template edit can never alter a signed agreement.
 *
 * `content_hash` (sha256 of rendered_html) is what the signature is computed
 * over, so tampering with the stored text invalidates the signature.
 *
 * `storage_disk` / `storage_path` exist so a PDF can be attached later without
 * a migration. No PDF library is added here -- that is a dependency decision,
 * and the legal text itself is TODO(business) B12.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('contract_template_id')->constrained()->restrictOnDelete();

            $table->string('template_key', 60);
            $table->unsignedInteger('template_version');
            $table->string('number', 40)->unique();

            $table->json('variables')->nullable();
            $table->longText('rendered_html');
            $table->char('content_hash', 64);

            $table->string('state', 32)->default('draft');

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_ip', 45)->nullable();
            $table->text('accepted_user_agent')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->string('storage_disk', 20)->nullable();
            $table->string('storage_path')->nullable();

            $table->timestamps();

            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
