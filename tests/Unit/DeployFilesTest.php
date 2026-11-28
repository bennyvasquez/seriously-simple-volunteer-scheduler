<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class DeployFilesTest extends TestCase
{
    private function deploy(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/deploy/'.$path);

        $this->assertIsString($contents, "deploy/{$path} should exist and be readable.");

        return $contents;
    }

    public function test_the_socket_path_matches(): void
    {
        $this->assertStringContainsString('fastcgi_pass unix:/run/php-fpm/ssvs.sock;', $this->deploy('nginx/ssvs.conf'));
        $this->assertStringContainsString('listen = /run/php-fpm/ssvs.sock', $this->deploy('php-fpm/ssvs.conf'));
    }

    public function test_the_app_folder_matches(): void
    {
        $this->assertStringContainsString('root /var/www/ssvs/public;', $this->deploy('nginx/ssvs.conf'));
        $this->assertStringContainsString('WorkingDirectory=/var/www/ssvs', $this->deploy('systemd/ssvs-scheduler.service'));
        $this->assertStringContainsString('/var/www/ssvs', $this->deploy('env.production.example'));
        $this->assertStringContainsString('APP=/var/www/ssvs', $this->deploy('bin/ssvs-restore'));
    }

    public function test_neither_nginx_file_mentions_a_certbot_webroot(): void
    {
        foreach (['nginx/ssvs-http.conf', 'nginx/ssvs.conf'] as $file) {
            $this->assertStringNotContainsString('/var/www/certbot', $this->deploy($file));
            $this->assertStringNotContainsString('acme-challenge', $this->deploy($file));
        }
    }

    /** The text of the server block that contains the given line. */
    private function serverBlockWith(string $nginx, string $line): string
    {
        foreach (preg_split('/(?=^server \{)/m', $nginx) as $block) {
            if (str_contains($block, $line)) {
                return $block;
            }
        }

        $this->fail("No server block contains {$line}");
    }

    public function test_unknown_hosts_get_444_on_both_ports(): void
    {
        $nginx = $this->deploy('nginx/ssvs-http.conf');

        $port80 = $this->serverBlockWith($nginx, 'listen 80 default_server;');
        $this->assertStringContainsString('return 444;', $port80);

        $port443 = $this->serverBlockWith($nginx, 'listen 443 ssl default_server;');
        $this->assertStringContainsString('return 444;', $port443);
        $this->assertStringContainsString('ssl_reject_handshake on;', $port443);

        $this->assertSame(1, substr_count($nginx, 'listen 80 default_server;'));
        $this->assertSame(1, substr_count($nginx, 'listen 443 ssl default_server;'));
    }

    public function test_the_http_file_needs_no_certificate(): void
    {
        $this->assertStringNotContainsString('ssl_certificate', $this->deploy('nginx/ssvs-http.conf'));
    }

    public function test_the_https_file_has_the_site_hardening(): void
    {
        $nginx = $this->deploy('nginx/ssvs.conf');

        $this->assertStringContainsString('server_tokens off;', $nginx);
        $this->assertStringContainsString('location ~ /\.(?!well-known) {', $nginx);
        $this->assertStringContainsString('deny all;', $nginx);
        $this->assertStringNotContainsString('default_server', $nginx);
        $this->assertStringContainsString('ssl_certificate /etc/letsencrypt/live/ssvs.example.org/fullchain.pem;', $nginx);
        $this->assertStringContainsString('ssl_certificate_key /etc/letsencrypt/live/ssvs.example.org/privkey.pem;', $nginx);
    }

    public function test_the_nginx_server_names_match_the_app_url(): void
    {
        $env = Dotenv::parse($this->deploy('env.production.example'));
        $host = parse_url($env['APP_URL'], PHP_URL_HOST);

        $this->assertSame('ssvs.example.org', $host);

        $this->assertStringContainsString("server_name {$host};", $this->deploy('nginx/ssvs.conf'));
        $redirect = $this->serverBlockWith($this->deploy('nginx/ssvs-http.conf'), 'return 301 https://$host$request_uri;');
        $this->assertStringContainsString("server_name {$host};", $redirect);
        $this->assertStringContainsString("/live/{$host}/", $this->deploy('nginx/ssvs.conf'));
    }

    public function test_the_template_from_address_is_one_check_install_rejects(): void
    {
        $env = Dotenv::parse($this->deploy('env.production.example'));

        // The same pattern as CheckInstallCommand::checkEmail(), so an admin who forgets this line is told.
        $this->assertMatchesRegularExpression('/@(example\.org|example\.com|localhost)$/i', $env['MAIL_FROM_ADDRESS']);
    }

    public function test_the_template_leaves_unfilled_secrets_empty_or_obviously_fake(): void
    {
        $env = Dotenv::parse($this->deploy('env.production.example'));

        $this->assertSame('', $env['APP_KEY']);
        $this->assertSame('', $env['MAIL_USERNAME']);
        $this->assertSame('', $env['MAIL_PASSWORD']);
        $this->assertStringStartsWith('CHANGE-ME', $env['DB_PASSWORD']);
    }

    public function test_every_development_key_is_in_the_production_template_or_deliberately_left_out(): void
    {
        // Keys that .env.example has and production doesn't need, each with the reason.
        $notNeededInProduction = [
            'PHP_CLI_SERVER_WORKERS', // Only for `php artisan serve` in development.
            'APP_MAINTENANCE_STORE', // Only used when APP_MAINTENANCE_DRIVER is cache or database.
            'CACHE_PREFIX', // The default prefix is fine for one install per server.
            'DB_PORT', // The database is reached through its socket, not TCP (Transmission Control Protocol).
            'MEMCACHED_HOST', // Memcached isn't used.
            'REDIS_CLIENT', // Redis isn't used.
            'REDIS_HOST',
            'REDIS_PASSWORD',
            'REDIS_PORT',
            'AWS_ACCESS_KEY_ID', // S3 storage isn't used.
            'AWS_SECRET_ACCESS_KEY',
            'AWS_DEFAULT_REGION',
            'AWS_BUCKET',
            'AWS_USE_PATH_STYLE_ENDPOINT',
            'VITE_APP_NAME', // There's no build step.
        ];

        $root = dirname(__DIR__, 2);
        $developmentFile = (string) file_get_contents($root.'/.env.example');
        $development = array_keys(Dotenv::parse($developmentFile));
        preg_match_all('/^# ([A-Z][A-Z0-9_]*)=/m', $developmentFile, $developmentCommented);
        $development = array_merge($development, $developmentCommented[1]);
        $production = array_keys(Dotenv::parse($this->deploy('env.production.example')));
        // Commented-out keys count too, because they're documented there (TRUSTED_PROXIES).
        preg_match_all('/^# ([A-Z][A-Z0-9_]*)=/m', $this->deploy('env.production.example'), $commented);
        $production = array_merge($production, $commented[1]);

        $missing = array_diff($development, $production, $notNeededInProduction);

        $this->assertSame([], array_values($missing), 'A key in .env.example is neither in deploy/env.production.example nor in the not-needed list.');

        // Keys only production sets: the database socket and the secure cookie flag.
        $onlyInProduction = array_values(array_diff($production, $development));
        sort($onlyInProduction);
        $this->assertSame(['DB_SOCKET', 'SESSION_SECURE_COOKIE'], $onlyInProduction);
    }

    public function test_the_production_template_has_production_values(): void
    {
        $env = Dotenv::parse($this->deploy('env.production.example'));

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('warning', $env['LOG_LEVEL']);
        $this->assertSame('', $env['APP_KEY']);
        $this->assertStringStartsWith('https://', $env['APP_URL']);
        $this->assertSame('database', $env['SESSION_DRIVER']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('database', $env['CACHE_STORE']);
        $this->assertSame('sync', $env['QUEUE_CONNECTION']);
        $this->assertSame('mariadb', $env['DB_CONNECTION']);
        $this->assertSame('localhost', $env['DB_HOST']);
        $this->assertSame('/var/lib/mysql/mysql.sock', $env['DB_SOCKET']);
        $this->assertSame('ssvs', $env['DB_DATABASE']);
        $this->assertSame('ssvs', $env['DB_USERNAME']);
        $this->assertNotContains($env['MAIL_MAILER'], ['log', 'array']);
    }

    public function test_the_production_template_sets_no_proxy(): void
    {
        $template = $this->deploy('env.production.example');

        $this->assertArrayNotHasKey('TRUSTED_PROXIES', Dotenv::parse($template));
        $this->assertMatchesRegularExpression('/^# TRUSTED_PROXIES=$/m', $template);
    }

    public function test_the_scheduler_runs_every_minute_as_the_app_user(): void
    {
        $service = $this->deploy('systemd/ssvs-scheduler.service');
        $timer = $this->deploy('systemd/ssvs-scheduler.timer');

        $this->assertStringContainsString('User=ssvs', $service);
        $this->assertStringContainsString('ExecStart=/usr/bin/php artisan schedule:run', $service);
        $this->assertStringContainsString('OnCalendar=*-*-* *:*:00', $timer);
        $this->assertStringContainsString('Persistent=false', $timer);
    }

    public function test_the_pool_runs_as_the_app_user_and_hands_the_socket_to_nginx(): void
    {
        $pool = $this->deploy('php-fpm/ssvs.conf');

        foreach (['user = ssvs', 'listen.owner = nginx', 'listen.group = nginx', 'listen.mode = 0660', 'pm = ondemand'] as $line) {
            $this->assertStringContainsString($line, $pool);
        }
    }

    public function test_restore_asks_before_dropping(): void
    {
        $script = $this->deploy('bin/ssvs-restore');

        $drop = strpos($script, 'DROP DATABASE');
        $this->assertNotFalse($drop, 'The restore script should drop the database.');
        $this->assertSame(1, substr_count($script, 'DROP DATABASE'));

        $tty = strpos($script, '! -t 0');
        $confirm = strpos($script, 'read -r');
        $down = strpos($script, 'artisan down');

        $this->assertNotFalse($tty, 'The restore script should refuse without a terminal.');
        $this->assertNotFalse($confirm, 'The restore script should read a typed confirmation.');
        $this->assertNotFalse($down, 'The restore script should put the site in maintenance mode.');
        $this->assertLessThan($drop, $tty);
        $this->assertLessThan($drop, $confirm);
        $this->assertLessThan($drop, $down);
        $this->assertLessThan($down, $confirm);
        $this->assertStringContainsString('artisan up', $script);
        $this->assertStringContainsString('still in maintenance mode', $script);
    }

    public function test_restore_proves_the_backup_reads_before_anything_changes(): void
    {
        $script = $this->deploy('bin/ssvs-restore');

        $verify = strpos($script, 'gunzip -t');
        $confirm = strpos($script, 'read -r');
        $down = strpos($script, 'artisan down');
        $rollback = strpos($script, '/usr/local/sbin/ssvs-backup');
        $drop = strpos($script, 'DROP DATABASE');

        foreach ([$verify, $confirm, $down, $rollback, $drop] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertStringContainsString('gpg --batch --decrypt "$file" | gunzip -t', $script);
        $this->assertLessThan($confirm, $verify);
        $this->assertLessThan($drop, $verify);
        $this->assertLessThan($rollback, $down);
        $this->assertLessThan($drop, $rollback);
        $this->assertStringContainsString('ssvs-backup: wrote ', $script);
        $this->assertStringContainsString('$rollback', $this->restoreFailureHandler());
    }

    public function test_the_http_file_hides_the_nginx_version_at_http_level(): void
    {
        $nginx = $this->deploy('nginx/ssvs-http.conf');

        // conf.d files are included inside nginx's http block, so a directive before the first server block applies to every server, the port 80 redirect included.
        $this->assertStringContainsString("\nserver_tokens off;\n", $nginx);
        $this->assertLessThan(strpos($nginx, 'server {'), strpos($nginx, 'server_tokens off;'));
    }

    public function test_restore_checks_the_backup_again_after_the_confirmation(): void
    {
        $script = $this->deploy('bin/ssvs-restore');

        $confirm = strpos($script, 'read -r');
        $down = strpos($script, 'artisan down');
        $this->assertNotFalse($confirm);
        $this->assertNotFalse($down);
        $this->assertSame(2, substr_count($script, 'gpg --batch --decrypt "$file" | gunzip -t'));

        $second = strrpos($script, 'gpg --batch --decrypt "$file" | gunzip -t');
        $this->assertGreaterThan($confirm, $second);
        $this->assertLessThan($down, $second);
    }

    public function test_restore_says_whether_the_database_was_changed_when_it_fails(): void
    {
        $script = $this->deploy('bin/ssvs-restore');
        $failed = $this->restoreFailureHandler();

        $this->assertStringContainsString('dropped=yes', $script);
        $this->assertLessThan(strpos($script, 'DROP DATABASE'), strpos($script, 'dropped=yes'));
        $this->assertStringContainsString('Nothing in the database was changed', $failed);
        $this->assertStringContainsString('runuser -u ssvs -- php artisan up', $failed);
        $this->assertStringContainsString('unlock', $failed);
        $this->assertStringContainsString('systemctl start ssvs-scheduler.timer', $failed);
        $this->assertStringContainsString('decrypting and importing the backup', $script);
        $this->assertStringNotContainsString('$BASH_COMMAND', $script);
    }

    public function test_restore_pauses_the_scheduler_and_clears_old_locks(): void
    {
        $script = $this->deploy('bin/ssvs-restore');
        // Positions in the main flow, after the failure handler.
        $main = (int) strpos($script, 'trap failed ERR');

        $stop = strpos($script, 'systemctl stop ssvs-scheduler.timer', $main);
        $down = strpos($script, 'artisan down', $main);
        $import = strpos($script, '| mariadb ssvs', $main);
        $clear = strpos($script, 'runuser -u ssvs -- php artisan schedule:clear-cache', $main);
        $up = strpos($script, 'runuser -u ssvs -- php artisan up', $main);
        $start = strpos($script, 'systemctl start ssvs-scheduler.timer', $main);
        $once = strpos($script, 'systemctl start ssvs-scheduler.service', $main);
        $check = strpos($script, 'php artisan ssvs:check-install', $main);

        foreach ([$stop, $down, $import, $clear, $up, $start, $once, $check] as $position) {
            $this->assertNotFalse($position);
        }
        $this->assertLessThan($down, $stop);
        $this->assertLessThan($clear, $import);
        $this->assertLessThan($up, $clear);
        $this->assertLessThan($start, $up);
        $this->assertLessThan($once, $start);
        $this->assertLessThan($check, $once);
    }

    /** The text of ssvs-restore's failure handler, from its definition to the line that sets it as the ERR trap. */
    private function restoreFailureHandler(): string
    {
        $script = $this->deploy('bin/ssvs-restore');
        $start = strpos($script, 'failed() {');
        $end = strpos($script, 'trap failed ERR');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertLessThan($end, $start);

        return substr($script, $start, $end - $start);
    }

    public function test_restore_leaves_a_scheduler_that_was_off_alone(): void
    {
        $script = $this->deploy('bin/ssvs-restore');
        $main = (int) strpos($script, 'trap failed ERR');

        $asked = strpos($script, 'systemctl is-active -q ssvs-scheduler.timer');
        $this->assertNotFalse($asked);
        $this->assertLessThan(strpos($script, 'systemctl stop ssvs-scheduler.timer', $main), $asked);
        $this->assertLessThan(strpos($script, 'artisan down', $main), $asked);
        $this->assertMatchesRegularExpression('/if \[\[ "\$scheduler_was_on" == yes \]\]; then\s+stage="stopping the scheduler timer"\s+systemctl stop ssvs-scheduler\.timer/', $script);
        $this->assertMatchesRegularExpression('/if \[\[ "\$scheduler_was_on" == yes \]\]; then\s+stage="starting the scheduler timer"\s+systemctl start ssvs-scheduler\.timer/', $script);
        $this->assertStringContainsString('the scheduler was left off, as it was before the restore', $script);
        $this->assertStringContainsString('scheduler line is expected to fail', $script);
    }

    public function test_restore_failures_after_the_import_say_the_backup_is_restored(): void
    {
        $script = $this->deploy('bin/ssvs-restore');
        $handler = $this->restoreFailureHandler();

        $this->assertLessThan(strpos($script, 'restored=yes'), strpos($script, 'runuser -u ssvs -- php artisan migrate --force', (int) strpos($script, 'trap failed ERR')));
        $this->assertStringContainsString('The backup is restored', $handler);
        $this->assertStringContainsString('runuser -u ssvs -- php artisan schedule:clear-cache', $handler);
        $this->assertStringContainsString('systemctl start ssvs-scheduler.service', $handler);
        $this->assertStringContainsString('in_maintenance', $handler);

        // The rollback is offered only while the database may be half restored, never once the backup is in.
        $from = strpos($handler, 'restored" == yes');
        $this->assertNotFalse($from);
        $to = strpos($handler, "\n    else\n", $from);
        $this->assertNotFalse($to);
        $restoredBranch = substr($handler, $from, $to - $from);
        $this->assertStringNotContainsString('$rollback', $restoredBranch);
    }

    public function test_restore_prints_the_key_reminder_before_the_install_check(): void
    {
        $script = $this->deploy('bin/ssvs-restore');
        // The main flow, after the failure handler (which also names the check, in its finish-by-hand steps).
        $main = (int) strpos($script, 'trap failed ERR');

        $this->assertLessThan(
            strpos($script, 'ssvs:check-install', $main),
            strpos($script, 'APP_KEY', $main),
        );
        $this->assertStringContainsString('the install check found problems', $script);
    }

    public function test_the_backup_checks_the_dump_itself_before_keeping_it(): void
    {
        $script = $this->deploy('bin/ssvs-backup');

        $mv = strpos($script, 'mv "$tmp"');
        $this->assertNotFalse($mv);
        $this->assertStringContainsString('tee "$fifo"', $script);
        $this->assertStringContainsString('-- Dump completed', $script);
        $this->assertLessThan($mv, strpos($script, '-- Dump completed'));
        $this->assertLessThan($mv, strpos($script, '1024'));
        $this->assertLessThan($mv, strpos($script, 'wait "$counter"'));
        $this->assertStringNotContainsString('stat -c', $script);
    }

    public function test_backups_are_encrypted_before_they_touch_disk(): void
    {
        $script = $this->deploy('bin/ssvs-backup');

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertMatchesRegularExpression('/mariadb-dump .*?\| gzip .*?\| gpg .*?--encrypt/s', $script);
        $this->assertStringContainsString('--recipient "$SSVS_BACKUP_RECIPIENT"', $script);
        $this->assertStringContainsString('umask 077', $script);
        $this->assertStringContainsString('.sql.gz.gpg', $script);
        $this->assertStringNotContainsString('.sql"', $script);
        $this->assertStringNotContainsString('.sql ', $script);
        $this->assertStringContainsString('> "$tmp"', $script);
        $this->assertStringNotContainsString('rclone', $script);
        $this->assertStringContainsString('-mtime', $script);
    }

    public function test_the_backup_service_and_timer(): void
    {
        $service = $this->deploy('systemd/ssvs-backup.service');
        $timer = $this->deploy('systemd/ssvs-backup.timer');

        $this->assertStringContainsString('Type=oneshot', $service);
        $this->assertStringContainsString('ExecStart=/usr/local/sbin/ssvs-backup', $service);
        $this->assertStringContainsString('Environment=GNUPGHOME=/root/.gnupg', $service);
        $this->assertStringContainsString('OnCalendar=*-*-* 02:30:00', $timer);
        $this->assertStringContainsString('RandomizedDelaySec=15m', $timer);
        $this->assertStringContainsString('Persistent=true', $timer);
    }

    public function test_the_backup_files_agree_on_the_app_and_the_settings(): void
    {
        $config = Dotenv::parse($this->deploy('backup.conf.example'));
        $this->assertSame('/var/backups/ssvs', $config['SSVS_BACKUP_DIR']);
        $this->assertSame('14', $config['SSVS_BACKUP_KEEP_DAYS']);
        $this->assertArrayHasKey('SSVS_BACKUP_RECIPIENT', $config);

        foreach (['bin/ssvs-backup', 'bin/ssvs-restore'] as $file) {
            $script = $this->deploy($file);
            $this->assertStringContainsString('/etc/ssvs/backup.conf', $script);
        }

        $this->assertStringContainsString('runuser -u ssvs --', $this->deploy('bin/ssvs-restore'));
    }

    public function test_no_deploy_file_uses_an_em_or_en_dash(): void
    {
        foreach (['env.production.example', 'nginx/ssvs-http.conf', 'nginx/ssvs.conf', 'php-fpm/ssvs.conf', 'systemd/ssvs-scheduler.service', 'systemd/ssvs-scheduler.timer', 'systemd/ssvs-backup.service', 'systemd/ssvs-backup.timer', 'backup.conf.example', 'bin/ssvs-backup', 'bin/ssvs-restore', 'test/smoke.sh'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $this->deploy($file), $file);
        }

        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $this->guide(), 'docs/install.md');
    }

    public function test_backup_names_have_seconds_and_never_overwrite(): void
    {
        $script = $this->deploy('bin/ssvs-backup');

        // ssvs-restore makes its rollback backup moments after the backup an admin may be restoring, so two backups in one minute must get different names.
        $this->assertStringContainsString('date +%Y-%m-%d-%H%M%S', $script);
        $this->assertLessThan(strpos($script, 'mariadb-dump --single-transaction'), strpos($script, 'already exists, so nothing was overwritten'));
    }

    public function test_every_command_in_the_guide_is_run_by_the_smoke_test(): void
    {
        $smoke = $this->deploy('test/smoke.sh');

        // The guide's commands that a container can't run as written, each with the reason. Everything else in a code block must appear in deploy/test/smoke.sh exactly.
        $skipped = [
            // firewalld doesn't run in a container.
            'systemctl enable --now firewalld',
            'firewall-cmd --permanent --add-service=https --add-service=http --add-service=ssh',
            'firewall-cmd --permanent --remove-service=cockpit',
            'firewall-cmd --reload',
            'firewall-cmd --list-services',
            // The smoke test puts its generated password in place of the placeholder.
            "CREATE USER 'ssvs'@'localhost' IDENTIFIED BY 'CHANGE-ME-database-password';",
            // The smoke test clones the mounted repository instead of GitHub.
            'runuser -u ssvs -- git clone https://github.com/bennyvasquez/seriously-simple-volunteer-scheduler.git /var/www/ssvs',
            // SELinux isn't on in a container.
            "semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/ssvs/(storage|bootstrap/cache)(/.*)?'",
            'restorecon -R /var/www/ssvs',
            'setsebool -P httpd_can_sendmail 1',
            'ausearch -m avc -ts recent',
            // Let's Encrypt can't issue a certificate for ssvs.example.org; the smoke test makes a self-signed one at the same path.
            'certbot certonly --nginx -d ssvs.example.org --deploy-hook "systemctl reload nginx"',
            'certbot renew --dry-run',
            // There's no terminal for the passphrase, so the smoke test passes it with loopback in these two.
            'gpg --quick-generate-key "SSVS backups <backups@example.org>" default default never',
            'gpg --armor --export-secret-keys backups@example.org > ssvs-backups-private.asc',
            // The smoke test keeps the template's backup settings, which is what nano would leave.
            'nano /etc/ssvs/backup.conf',
            // The smoke test uses the key's real fingerprint.
            'gpg --batch --yes --delete-secret-keys FINGERPRINT',
        ];

        preg_match_all('/^ *```\n(.*?)^ *```$/ms', $this->guide(), $blocks);
        $this->assertNotEmpty($blocks[1]);

        $checked = 0;
        foreach ($blocks[1] as $block) {
            foreach (explode("\n", $block) as $line) {
                $command = trim($line);
                if ($command === '' || in_array($command, $skipped, true)) {
                    continue;
                }
                $this->assertStringContainsString($command, $smoke, "docs/install.md runs this, but deploy/test/smoke.sh doesn't: {$command}");
                $checked++;
            }
        }
        $this->assertGreaterThan(50, $checked);

        foreach ($skipped as $command) {
            $this->assertStringContainsString($command, $this->guide(), "This skipped command is no longer in docs/install.md, so take it off the list: {$command}");
        }
    }

    private function guide(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/docs/install.md');

        $this->assertIsString($contents, 'docs/install.md should exist and be readable.');

        return $contents;
    }
}
