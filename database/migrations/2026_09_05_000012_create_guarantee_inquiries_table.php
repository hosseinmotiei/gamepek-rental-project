<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per CHEQUE-01..07 inquiry run against a guarantee, each with its
 * own independent state and its own retention clock for the raw payload.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guarantee_id')->constrained()->cascadeOnDelete();

            // sayad_validate|cheque_inquiry|ownership_match|bounced_cheque
            // |credit_risk|aggregate|status
            $table->string('kind', 32);
            $table->string('provider', 40)->nullable();
            $table->string('state', 24)->default('checking');
            $table->string('result', 16)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('provider_status_code', 40)->nullable();

            $table->uuid('request_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->json('fields')->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();

            $table->date('retention_until')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['guarantee_id', 'kind'], 'guarantee_inquiries_kind_idx');
            $table->index('correlation_id');
            $table->index('retention_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_inquiries');
    }
};
