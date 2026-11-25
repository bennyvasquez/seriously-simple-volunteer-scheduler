<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/visitor', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]));
    }

    private function trusting(?string $proxies): void
    {
        config(['app.trusted_proxies' => $proxies]);
    }

    private function visit(string $from = '10.0.0.1'): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_test/visitor')
            ->json();
    }

    public function test_by_default_a_forwarded_for_header_is_ignored(): void
    {
        $this->assertSame(['ip' => '10.0.0.1', 'secure' => false], $this->visit());
    }

    public function test_a_configured_proxy_is_trusted(): void
    {
        $this->trusting('10.0.0.1');

        $this->assertSame(['ip' => '203.0.113.9', 'secure' => true], $this->visit());
    }

    public function test_an_unlisted_proxy_is_not_trusted(): void
    {
        $this->trusting('10.0.0.1');

        $this->assertSame(['ip' => '10.0.0.7', 'secure' => false], $this->visit('10.0.0.7'));
    }

    public function test_a_list_with_spaces_trusts_each_address(): void
    {
        $this->trusting('10.0.0.5, 10.0.0.1 ,');

        $this->assertSame('203.0.113.9', $this->visit()['ip']);
    }

    public function test_a_star_trusts_every_sender_only_when_it_is_set(): void
    {
        $this->trusting('*');

        $this->assertSame('203.0.113.9', $this->visit('10.0.0.7')['ip']);
    }
}
