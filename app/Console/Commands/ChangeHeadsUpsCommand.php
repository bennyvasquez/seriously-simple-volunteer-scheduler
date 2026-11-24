<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\SendChangeHeadsUps;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ChangeHeadsUpsCommand extends Command
{
    protected $signature = 'ssvs:change-heads-ups';

    protected $description = 'Tell leads, 48 hours before a shift, who still hasn\'t answered its time change';

    public function handle(SendChangeHeadsUps $heads): int
    {
        $count = $heads->run();

        $this->info('Told leads about unanswered time changes on '.$count.' '.Str::plural('shift', $count).'.');

        return self::SUCCESS;
    }
}
