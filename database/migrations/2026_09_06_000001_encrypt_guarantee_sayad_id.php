<?php

use App\Models\Guarantee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the Sayad id into line with the way every other sensitive value in
 * this project is stored (see user_identities.national_code_* and
 * bank_accounts.value_*): encrypted at rest, a keyed HMAC for lookup and for
 * the unique constraint, a mask for display.
 *
 * A Sayad id identifies one cheque nationally, so the uniqueness the old
 * plaintext column enforced moves to sayad_id_hash -- it is not dropped.
 *
 * A second unique index lands on rental_application_id: GuaranteeService uses
 * updateOrCreate() keyed on it, and an application-level check alone does not
 * survive two concurrent submissions (.claude/rules/database.md).
 *
 * down(): restores the plaintext column and back-fills it by decrypting, then
 * drops the three new columns and the application unique index. No data loss
 * in either direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantees', function (Blueprint $table) {
            $table->text('sayad_id_encrypted')->nullable()->after('type');
            $table->char('sayad_id_hash', 64)->nullable()->unique()->after('sayad_id_encrypted');
            $table->string('sayad_id_mask', 24)->nullable()->after('sayad_id_hash');
        });

        DB::table('guarantees')->whereNotNull('sayad_id')->orderBy('id')
            ->each(function ($row) {
                $guarantee = new Guarantee;
                $guarantee->setSayadId((string) $row->sayad_id);

                DB::table('guarantees')->where('id', $row->id)->update([
                    'sayad_id_encrypted' => $guarantee->sayad_id_encrypted,
                    'sayad_id_hash' => $guarantee->sayad_id_hash,
                    'sayad_id_mask' => $guarantee->sayad_id_mask,
                ]);
            });

        Schema::table('guarantees', function (Blueprint $table) {
            $table->dropUnique(['sayad_id']);
            $table->dropColumn('sayad_id');
            $table->unique('rental_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('guarantees', function (Blueprint $table) {
            $table->string('sayad_id', 16)->nullable()->after('type');
        });

        Guarantee::whereNotNull('sayad_id_encrypted')->orderBy('id')
            ->each(function (Guarantee $guarantee) {
                DB::table('guarantees')->where('id', $guarantee->id)
                    ->update(['sayad_id' => $guarantee->sayadId()]);
            });

        Schema::table('guarantees', function (Blueprint $table) {
            $table->dropUnique(['rental_application_id']);
            $table->dropUnique(['sayad_id_hash']);
            $table->dropColumn(['sayad_id_encrypted', 'sayad_id_hash', 'sayad_id_mask']);
            $table->unique('sayad_id');
        });
    }
};
