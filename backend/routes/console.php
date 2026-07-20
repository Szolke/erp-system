<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// MNB publishes the daily HUF middle rate around noon on business days;
// 13:00 gives a safety margin. Skips weekends (no publication).
Schedule::command('exchange-rates:fetch-mnb')
    ->weekdays()
    ->at('13:00')
    ->withoutOverlapping();

// NAV never calls back with a verdict — this is the guarantee that closes the
// loop on every submission (see docs/nav-logging-audit.md phase 2). Self-healing:
// re-queries current DB state every run, so a lost worker or a dropped
// CheckNavTransactionStatusJob gets picked up on the next sweep regardless.
Schedule::command('nav:check-submission-status')
    ->everyFiveMinutes()
    ->withoutOverlapping();
