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
