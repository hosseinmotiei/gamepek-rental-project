<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 30)->default('mock');  // mock, zarinpal, idpay, etc.
            $table->unsignedBigInteger('amount');
            $table->enum('status', ['pending', 'success', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->string('authority')->nullable();   // Gateway reference token
            $table->string('tracking_code')->nullable();  // Final tracking code after success
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('gateway');
            $table->index('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
