<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GamePek expert's damage assessment of a returned device (C-39).
 *
 * CONFIRMED: GamePek's expert determines the damage amount. So the amount is
 * entered by that person, whole Toman, and is recorded as their statement.
 *
 * DELIBERATELY ABSENT: category, severity, formula, depreciation, responsible
 * party, charge status. None is decided. Recording an amount charges nobody,
 * touches no wallet and changes no deposit.
 *
 * APPEND-ONLY. A revised assessment is a new row; the history of what each
 * expert said, and when, is kept. Which row "counts" is not decided and not
 * encoded.
 *
 * Every reference is copied from the inspection it is attached to, never from
 * a request. unsignedBigInteger already refuses a negative amount.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_damage_assessments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rental_inspection_id')->constrained('rental_inspections')->restrictOnDelete();
            $table->foreignId('rental_operation_id')->constrained('rental_operations')->restrictOnDelete();
            $table->foreignId('rental_reservation_id')->constrained('rental_reservations')->restrictOnDelete();
            $table->foreignId('rental_application_id')->constrained('rental_applications')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();

            $table->unsignedBigInteger('amount');
            $table->text('notes')->nullable();
            // A pointer to evidence held elsewhere (photo id, report number).
            // Not a file store: media retention (B11) is undecided.
            $table->string('evidence_reference', 255)->nullable();

            $table->foreignId('assessed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('assessed_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['rental_application_id', 'id']);
            $table->index(['rental_inspection_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_damage_assessments');
    }
};
