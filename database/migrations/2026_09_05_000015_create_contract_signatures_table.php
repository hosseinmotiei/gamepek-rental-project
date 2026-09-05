<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CON-04/05: the signature and its evidence.
 *
 * unique(contract_id, user_id) is the database-layer backstop against double
 * signing -- .claude/rules/database.md is explicit that an application-level
 * check alone is not sufficient.
 *
 * `signed_content_hash` is the contract hash AT THE MOMENT OF SIGNING. Verify
 * re-hashes the stored rendered_html and compares, so text tampering is caught
 * independently of the HMAC itself.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('method', 24)->default('sms_otp');
            $table->char('signed_content_hash', 64);
            $table->char('signature', 64);
            $table->string('otp_reference')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('evidence')->nullable();

            $table->timestamp('signed_at');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->unique(['contract_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signatures');
    }
};
