<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\GenerateShifts;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateShiftsCommand extends Command
{
    protected $signature = 'ssvs:generate-shifts';

    protected $description = 'Create shifts from weekly slots for the coming weeks';

    public function handle(GenerateShifts $generateShifts): int
    {
        if (Organization::query()->doesntExist()) {
            $this->error('No organization is set up yet. Create one before generating shifts.');

            return self::FAILURE;
        }

        $created = $generateShifts->handle(Organization::current());

        $this->info('Created '.$created.' '.Str::plural('shift', $created).'.');

        return self::SUCCESS;
    }
}
