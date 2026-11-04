<?php

namespace App\Console\Commands;

use App\Actions\Accounts\ImportPeople;
use App\Enums\Role;
use App\Import\HelloClubExport;
use App\Models\Area;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ImportPeopleCommand extends Command
{
    protected $signature = 'ssvs:import-people
        {file : The Hello Club members export (a CSV file)}
        {--as= : The email address of the admin running the import}
        {--lead-tag= : A Hello Club category that makes someone an area lead}
        {--lead-area= : The area those people lead}';

    protected $description = 'Add the people in a Hello Club members export, as not invited yet. Nobody is emailed.';

    public function handle(ImportPeople $importPeople): int
    {
        $admin = User::query()->people()->where('email', Str::lower(trim((string) $this->option('as'))))->first();

        if ($admin === null || ! $admin->isActive() || ! $admin->hasRole(Role::Admin)) {
            $this->error('Use --as with the email address of an active admin.');

            return self::FAILURE;
        }

        $leadTag = $this->option('lead-tag');
        $leadAreaName = $this->option('lead-area');

        if (($leadTag === null) !== ($leadAreaName === null)) {
            $this->error('Use --lead-tag and --lead-area together.');

            return self::FAILURE;
        }

        $leadArea = null;

        if ($leadAreaName !== null) {
            $leadArea = Area::query()->where('name', $leadAreaName)->first();

            if ($leadArea === null) {
                $this->error("There's no area called {$leadAreaName}. Add it on the calendar first.");

                return self::FAILURE;
            }
        }

        try {
            $preview = $importPeople->preview(HelloClubExport::read($this->argument('file')), $leadTag);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Names and email addresses only. Nothing else from the file is shown.
        $this->showGroup("Will be added ({$preview->add->count()})", ['Name', 'Email'], $preview->add->map(fn ($item) => [$item->row->name, $item->row->email.($item->makeLead ? ' (leads '.$leadArea->name.')' : '')]));
        $this->showGroup("Already here, left alone ({$preview->existing->count()})", ['Name', 'Email'], $preview->existing->map(fn ($item) => [$item->row->name, $item->row->email.($item->fillMemberId ? ' (membership ID will be added)' : '')]));
        $this->showGroup("Skipped ({$preview->problems->count()})", ['Name', 'Email', 'Why'], $preview->problems->map(fn ($item) => [$item->row->name, $item->row->email, $item->reason]));

        $fills = $preview->existing->where('fillMemberId', true)->count();

        if ($preview->add->isEmpty() && $fills === 0) {
            $this->info("There's nobody to add.");

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            return self::SUCCESS;
        }

        $count = $preview->add->count();
        $question = $count === 0
            ? ($fills === 1 ? 'Add a membership ID to 1 person? Nobody will be emailed.' : "Add membership IDs to {$fills} people? Nobody will be emailed.")
            : ($count === 1 ? 'Add this 1 person? Nobody will be emailed.' : "Add these {$count} people? Nobody will be emailed.");

        if (! $this->confirm($question)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $created = $importPeople->apply($admin, $preview, $leadArea);
        } catch (AuthorizationException|DomainException $exception) {
            $this->error($exception instanceof DomainException ? $exception->getMessage() : "Nothing was saved: {$exception->getMessage()}");

            return self::FAILURE;
        } catch (QueryException) {
            // Never echo the exception: its message holds the SQL bindings, which are names and email addresses.
            $this->error('Something went wrong, so nothing was saved.');

            return self::FAILURE;
        }

        $this->info("Added {$created} ".($created === 1 ? 'person' : 'people').' as not invited yet. Nobody was emailed.');

        return self::SUCCESS;
    }

    private function showGroup(string $title, array $headers, $rows): void
    {
        $this->newLine();
        $this->line($title);

        if ($rows->isNotEmpty()) {
            $this->table($headers, $rows->all());
        }
    }
}
