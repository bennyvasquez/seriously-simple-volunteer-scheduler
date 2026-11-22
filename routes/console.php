<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the generation window full. Safe to run more than once; existing shifts are never changed. The 03:00 is UTC on purpose: the window comes from the organization's own date, so the run time doesn't matter.
Schedule::command('ssvs:generate-shifts')->dailyAt('03:00')->withoutOverlapping();

// Walk open cover requests up the escalation ladder: sent again 72 hours before, urgent 24 hours before. Each step happens once per request, so running it often only makes the timing closer.
Schedule::command('ssvs:cover-requests')->everyTenMinutes()->withoutOverlapping();
