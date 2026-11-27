<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckInstallCommand extends Command
{
    protected $signature = 'ssvs:check-install';

    protected $description = "Check that this server's setup is ready for real volunteers, and say how to fix anything that isn't";

    private bool $failed = false;

    /** Reads config() and never env(), so it works with a cached configuration. */
    public function handle(Migrator $migrator): int
    {
        $this->checkEnvironment();
        $this->checkAppKey();
        $this->checkSiteAddress();
        $this->checkEmail();
        $this->checkSessions();

        if ($this->checkDatabase($migrator)) {
            $this->checkOrganization();
        }

        $this->checkWritableFolders();
        $this->checkScheduler();
        $this->checkProxies();

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function ok(string $what): void
    {
        $this->line('OK  '.$what);
    }

    private function fix(string $what, string $how): void
    {
        $this->failed = true;
        $this->line('FIX '.$what.': '.$how);
    }

    private function checkEnvironment(): void
    {
        $env = (string) config('app.env');

        if ($env === 'production') {
            $this->ok('Environment is production');
        } else {
            $this->fix('Environment', 'APP_ENV is "'.$env.'". Set APP_ENV=production in .env.');
        }
    }

    private function checkAppKey(): void
    {
        if (config('app.key')) {
            $this->ok('App key is set');
        } else {
            $this->fix('App key', 'APP_KEY is empty. Run php artisan key:generate once, then keep a copy of .env somewhere safe off the server.');
        }
    }

    private function checkSiteAddress(): void
    {
        $url = (string) config('app.url');

        if (preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#', $url) === 1) {
            $this->ok('Site address is '.$url);
        } else {
            $this->fix('Site address', 'APP_URL is "'.$url.'". Set it to the site\'s public address, like https://ssvs.example.org, with no path or trailing slash.');
        }
    }

    private function checkEmail(): void
    {
        $mailer = (string) config('mail.default');
        $address = (string) config('mail.from.address');
        $good = true;

        if (in_array($mailer, ['log', 'array'], true)) {
            $good = false;
            $this->fix('Email', 'MAIL_MAILER is "'.$mailer.'". Set it to smtp and fill in MAIL_HOST, MAIL_PORT, MAIL_USERNAME and MAIL_PASSWORD.');
        }

        if (preg_match('/@(example\.org|example\.com|localhost)$/i', $address) === 1) {
            $good = false;
            $this->fix('Email', 'MAIL_FROM_ADDRESS is "'.$address.'". Set it to an address your organization reads.');
        }

        if ($good) {
            $this->ok('Email is set up to send from '.$address);
        }
    }

    private function checkSessions(): void
    {
        $driver = (string) config('session.driver');
        $good = true;

        if ($driver !== 'database') {
            $good = false;
            $this->fix('Sessions', 'SESSION_DRIVER is "'.$driver.'". Set SESSION_DRIVER=database, which signing out everywhere needs.');
        }

        if (config('session.secure') !== true) {
            $good = false;
            $this->fix('Sessions', "SESSION_SECURE_COOKIE isn't on. Set SESSION_SECURE_COOKIE=true.");
        }

        if ($good) {
            $this->ok('Sessions are in the database with secure cookies');
        }
    }

    /** Returns whether the database could be reached and is fully migrated, so the checks that read it can run. */
    private function checkDatabase(Migrator $migrator): bool
    {
        try {
            DB::connection()->getPdo();

            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $files = array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
            $pending = count(array_diff($files, $ran));
        } catch (Throwable $e) {
            $this->fix('Database', "Can't reach the database: ".$e->getMessage().'.');

            return false;
        }

        if ($pending > 0) {
            $this->fix('Database', $pending.' '.($pending === 1 ? "migration hasn't" : "migrations haven't").' run. Run php artisan migrate --force.');

            return false;
        }

        $this->ok('Database is reachable and up to date');

        return true;
    }

    private function checkOrganization(): void
    {
        if (Schema::hasTable('organizations') && Organization::query()->exists()) {
            $this->ok('Organization is set up');
        } else {
            $this->fix('Organization', "There's no organization yet. Run php artisan ssvs:setup.");
        }
    }

    private function checkWritableFolders(): void
    {
        $good = true;

        foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
            if (! is_writable($path)) {
                $good = false;
                $this->fix('Writable folders', $path.' isn\'t writable. See "File permissions" in docs/install.md.');
            }
        }

        if ($good) {
            $this->ok('storage and bootstrap/cache are writable');
        }
    }

    private function checkScheduler(): void
    {
        try {
            $last = SchedulerHeartbeat::lastBeat();
        } catch (Throwable) {
            $last = null;
        }

        if ($last !== null && $last->greaterThanOrEqualTo(now()->subMinutes(5))) {
            $this->ok('Scheduler ran at '.$last->toDateTimeString().' UTC');

            return;
        }

        $when = $last === null ? 'has never run' : "hasn't run since ".$last->setTimezone('UTC')->format('Y-m-d H:i').' UTC';
        $this->fix('Scheduler', 'The scheduler '.$when.'. Run systemctl enable --now ssvs-scheduler.timer.');
    }

    private function checkProxies(): void
    {
        if (config('app.trusted_proxies') === '*') {
            $this->line('NOTE TRUSTED_PROXIES is "*", which is only safe when the app can be reached only through your proxy.');
        }
    }
}
