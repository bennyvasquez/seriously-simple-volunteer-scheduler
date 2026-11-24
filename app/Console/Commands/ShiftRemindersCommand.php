<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\SendShiftReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ShiftRemindersCommand extends Command
{
    protected $signature = 'ssvs:shift-reminders';

    protected $description = 'Email everyone on a shift a reminder, the number of hours before it that the organization chose';

    public function handle(SendShiftReminders $reminders): int
    {
        $count = $reminders->run();

        $this->info('Sent '.$count.' shift '.Str::plural('reminder', $count).'.');

        return self::SUCCESS;
    }
}
