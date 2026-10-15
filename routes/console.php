<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the generation window full. Safe to run more than once; existing shifts are never changed.
Schedule::command('ssvs:generate-shifts')->dailyAt('03:00')->withoutOverlapping();
