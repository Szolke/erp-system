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

// NAV eNyugta napi nyugta-összesítő — a Budapest-nap zárása után fut, hogy az
// előző (teljes) napra vonatkozó nyugták mind rendelkezésre álljanak (D5,
// docs/nav-enyugta-spec-jegyzetek.md). Explicit ->timezone(): az APP_TIMEZONE
// UTC (config/app.php), a "01:00" enélkül UTC szerint értendő, nem
// Budapest szerint — a napi határ (D5) itt is Europe/Budapest kell legyen.
Schedule::command('erp:build-receipt-reports')
    ->dailyAt('01:00')
    ->timezone('Europe/Budapest')
    ->withoutOverlapping();
