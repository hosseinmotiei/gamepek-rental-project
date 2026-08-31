<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Clean up expired OTP codes daily. App\Models\OtpCode implements
// MassPrunable, without which this command silently does nothing.
Schedule::command('model:prune', ['--model' => \App\Models\OtpCode::class])->daily();

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
