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

// Once per shift, 48 hours before it starts, tell its leads who hasn't answered a time change. Safe to run often: each shift is told once.
Schedule::command('ssvs:change-heads-ups')->everyTenMinutes()->withoutOverlapping();

// Remind people of their shifts, the number of hours before each that the organization chose in Settings (Off sends nothing). Safe to run often: each place on a shift is handled once.
Schedule::command('ssvs:shift-reminders')->everyTenMinutes()->withoutOverlapping();
