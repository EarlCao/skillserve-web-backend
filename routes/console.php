<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lift temporary bans as soon as their duration elapses (belt-and-braces:
// logins also auto-recover expired bans). Requires the scheduler to run —
// the backend container starts `schedule:work` alongside the web server.
Schedule::command('users:unban-expired')->everyMinute();

// Permanently remove deleted records 30 days after deletion (records that
// related data still references are kept).
Schedule::command('data-management:purge-expired')->daily();

// National ID images are deleted once System Settings → Identity says their
// retention has passed. The verification decision and its history are kept.
Schedule::command('identity:purge-documents')->daily();
