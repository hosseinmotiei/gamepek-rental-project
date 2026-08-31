<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 3 Task 4: Pardakht Novin Reverse. The existing raw_request/
     * raw_response columns already hold the NormalSale/Confirm gateway
     * interaction history for a transaction -- reusing them for Reverse
     * would silently overwrite that audit trail. Dedicated columns are
     * added instead, mirroring the existing raw_request/raw_response naming.
     *
     * `status` itself gets no new enum value: the existing 'refunded' value
     * (already present in the original enum, previously unused/dead) is
     * reused as the "reverse succeeded" status -- no enum change needed.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('paid_at');
            $table->json('reverse_raw_request')->nullable()->after('raw_response');
            $table->json('reverse_raw_response')->nullable()->after('reverse_raw_request');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['reversed_at', 'reverse_raw_request', 'reverse_raw_response']);
        });
    }
};
