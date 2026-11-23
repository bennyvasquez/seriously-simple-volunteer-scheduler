<?php

namespace App\Console\Commands;

use App\Cover\CoverLadder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CoverRequestsCommand extends Command
{
    protected $signature = 'ssvs:cover-requests';

    protected $description = 'Send open cover requests again 72 hours before and mark them urgent 24 hours before';

    public function handle(CoverLadder $ladder): int
    {
        $counts = $ladder->run();

        $this->info('Sent cover requests again for '.$counts['resent'].' '.Str::plural('shift', $counts['resent']).' and marked '.$counts['escalated'].' '.Str::plural('shift', $counts['escalated']).' urgent.');

        return self::SUCCESS;
    }
}
