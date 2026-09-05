<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The aggregate root of the rental chain.
 *
 * `state` is DERIVED by RentalChainOrchestrator from the child records
 * (identity, bank account, reservation, order, guarantee, contract) -- no step
 * service ever writes it directly. That is what makes advancing the chain
 * idempotent: re-running it against unchanged children produces the same
 * answer and writes no transition row.
 *
 * `order_id` is nullable and restrictOnDelete: order history is
 * retention-sensitive (.claude/rules/database.md) and an application must
 * never cascade-delete an order.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_number', 40)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();

            $table->string('state', 40)->default('draft');
            $table->uuid('correlation_id')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->text('admin_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'state']);
            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_applications');
    }
};
