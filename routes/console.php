<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosting has no permanent queue worker: the server cron runs
// `schedule:run` every minute and this drains the queue (WhatsApp
// messages, receipts, admin decisions) in a run that ends before the next
// minute. The lock expires after 5 minutes if a run dies.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5);
