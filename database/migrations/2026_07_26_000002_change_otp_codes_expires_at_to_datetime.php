<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * otp_codes.expires_at was `timestamp` and NOT NULL with no explicit
     * default. On this server (explicit_defaults_for_timestamp=OFF), MySQL/
     * MariaDB implicitly gives the FIRST such TIMESTAMP column in a table
     * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` -- confirmed via
     * `SHOW CREATE TABLE otp_codes`. That means ANY update to an otp_codes
     * row (e.g. OtpService::verify()'s `$otp->increment('attempts')` on a
     * wrong attempt) silently reset expires_at to the current moment,
     * making an otherwise-valid, unexpired OTP fail verification right
     * after the very first wrong attempt. DATETIME columns are never
     * subject to this implicit-default behavior (it's TIMESTAMP-specific)
     * and are also timezone-naive, so switching type fixes both this and
     * any session-timezone-conversion ambiguity for this column.
     */
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->timestamp('expires_at')->change();
        });
    }
};
