<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `contract_signatures.signed_at` is the first non-nullable TIMESTAMP column in
 * its table, so MySQL gave it an implicit ON UPDATE CURRENT_TIMESTAMP. Any
 * later write to the row -- ContractService::verifySignature() stamping
 * `verified_at` -- silently rewrote when the contract had been signed.
 *
 * The HMAC itself was unaffected (it is computed over the ISO timestamp stored
 * inside `evidence`, not this column), so no signature was invalidated; the
 * recorded signing time was simply wrong after the first verification.
 *
 * DATETIME carries no implicit behaviour. Existing values are preserved by the
 * type change; only the trigger goes away.
 *
 * down(): restores the TIMESTAMP column, implicit ON UPDATE included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->dateTime('signed_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->timestamp('signed_at')->change();
        });
    }
};
