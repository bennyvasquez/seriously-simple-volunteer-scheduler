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
        $this->assertStringContainsString('/run/php-fpm/ssvs.sock', $this->deploy('nginx/ssvs.conf'));
        $this->assertStringContainsString('/run/php-fpm/ssvs.sock', $this->deploy('php-fpm/ssvs.conf'));
    }

    public function test_the_app_folder_matches(): void
    {
        $this->assertStringContainsString('/var/www/ssvs/public', $this->deploy('nginx/ssvs.conf'));
        $this->assertStringContainsString('WorkingDirectory=/var/www/ssvs', $this->deploy('systemd/ssvs-scheduler.service'));
    }

    public function test_unknown_hosts_get_444(): void
    {
        $nginx = $this->deploy('nginx/ssvs.conf');

        foreach (['listen 80 default_server;', 'listen 443 ssl default_server;'] as $listen) {
            $this->assertStringContainsString($listen, $nginx);
        }

        $this->assertSame(2, substr_count($nginx, 'return 444;'));
        $this->assertStringContainsString('ssl_reject_handshake on;', $nginx);
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

    public function test_no_deploy_file_uses_an_em_or_en_dash(): void
    {
        foreach (['env.production.example', 'nginx/ssvs.conf', 'php-fpm/ssvs.conf', 'systemd/ssvs-scheduler.service', 'systemd/ssvs-scheduler.timer'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $this->deploy($file), $file);
        }
    }
}
