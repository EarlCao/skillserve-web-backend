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
