<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private function assertSecurityHeaders($response): void
    {
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::POLICY);
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    }

    public function test_every_page_sends_the_security_headers(): void
    {
        $this->assertSecurityHeaders($this->get('/login'));

        Organization::factory()->create();
        $admin = User::factory()->create();
        app(GrantRole::class)->handle(null, $admin, Role::Admin);
        $this->enableTwoFactor($admin);

        foreach (['/', '/calendar', '/emails', '/emails/shift_reminder'] as $path) {
            $response = $this->actingAs($admin)->get($path);
            $response->assertOk();
            $this->assertSecurityHeaders($response);
        }
    }

    public function test_an_address_with_no_page_gets_them_too(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        $this->assertSecurityHeaders($response);
    }

    public function test_an_error_page_gets_them(): void
    {
        Route::get('/_test/broken', fn () => abort(500));

        $this->assertSecurityHeaders($this->get('/_test/broken'));
    }

    public function test_the_policy_allows_no_inline_scripts_or_style_elements(): void
    {
        $this->assertStringContainsString("script-src 'self';", SecurityHeaders::POLICY);
        $this->assertStringContainsString("style-src 'self';", SecurityHeaders::POLICY);
        $this->assertStringNotContainsString("'unsafe-eval'", SecurityHeaders::POLICY);
        $this->assertStringNotContainsString('script-src \'unsafe-inline\'', SecurityHeaders::POLICY);
    }

    public function test_hsts_is_sent_only_in_production_over_https(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';

        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_no_view_has_an_inline_script_handler_or_style_element(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $text = $file->getContents();

            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $text, $file->getRelativePathname().' has an inline script');
            $this->assertDoesNotMatchRegularExpression('/<style[\s>]/i', $text, $file->getRelativePathname().' has a style element');
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $text, $file->getRelativePathname().' has an inline event handler');
        }
    }

    public function test_only_the_calendar_grid_uses_style_attributes(): void
    {
        $allowed = ['calendar/week.blade.php', '_shift-block.blade.php'];
        $found = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (preg_match('/\sstyle\s*=/i', $file->getContents())) {
                $found[] = $file->getFilename() === '_shift-block.blade.php' ? '_shift-block.blade.php' : $file->getRelativePathname();
            }
        }

        sort($found);
        sort($allowed);
        $this->assertSame(array_map(fn ($p) => str_replace('\\', '/', $p), $allowed), array_map(fn ($p) => str_replace('\\', '/', $p), $found));
    }

    public function test_the_authenticator_qr_code_has_no_style_attributes(): void
    {
        $user = User::factory()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);

        $svg = $user->twoFactorQrCodeSvg();

        $this->assertStringContainsString('<svg', $svg);
        $this->assertDoesNotMatchRegularExpression('/style\s*=|<style/i', $svg);
    }
}
