<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS-06: the log every outbound message writes, whatever the provider.
 *
 * `attempts` and `last_error` drive SMS-04 retry; `provider_message_id` and
 * `delivered_at` drive SMS-03 delivery status, which most Iranian providers
 * report asynchronously rather than in the send response.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile', 15);

            $table->string('template_key', 60)->nullable();
            $table->json('params')->nullable();
            $table->text('body');

            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('state', 24)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('delivery_checked_at')->nullable();

            $table->uuid('correlation_id')->nullable();

            $table->timestamps();

            $table->index(['state', 'attempts']);
            $table->index(['mobile', 'created_at']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
