<?php

use App\Models\OtpCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Clean up expired OTP codes daily. App\Models\OtpCode implements
// MassPrunable, without which this command silently does nothing.
Schedule::command('model:prune', ['--model' => OtpCode::class])->daily();

// PAY-06. A customer who closes the bank tab never reaches the callback, so
// the transaction stays `pending` even though money may have moved. This asks
// the gateway and routes any resolution through the same settlement path the
// public callback uses -- it never writes `orders` itself.
Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping();

// VID-06. Deletes verification media past its retention window, keeping the
// row so the audit trail outlives the file. A media kind whose retention is
// still null (the default, TODO(business) B11) is SKIPPED, never deleted on a
// guessed policy.
Schedule::command('verification:purge-media')->dailyAt('03:30')->withoutOverlapping();

// SMS-03 / SMS-04.
Schedule::command('sms:sync-delivery')->everyThirtyMinutes()->withoutOverlapping();

// Scheduler wiring note, inherited from the Store and still required here:
// none of the above runs unless the server's OS-level cron actually invokes
// Laravel's scheduler. Required production crontab entry (run once, on the
// app server, as the deploy user):
//
//   * * * * * cd /path/to/gamepek-rental && php artisan schedule:run >> /dev/null 2>&1
//
// The rental lifecycle (reservation expiry, return reminders, overdue
// notices) will hang off this same scheduler, so verify the cron entry
// exists before relying on any of it.
