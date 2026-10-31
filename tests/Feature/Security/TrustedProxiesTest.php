<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function trust(?string $setting): void
    {
        TrustProxies::flushState();
        config(['security.trusted_proxies' => $setting]);
        $method = new \ReflectionMethod(AppServiceProvider::class, 'configureTrustedProxies');
        $method->invoke(new AppServiceProvider($this->app));
    }

    private function visit(string $remoteAddr): \Illuminate\Testing\TestResponse
    {
        $school = \App\Models\School::query()->where('slug', 'proxy')->first() ?? $this->provisionSchool('proxy');

        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-Host' => 'evil.example'])
            ->get($this->schoolUrl($school, '/login'));
    }

    public function test_cloudflare_setting_trusts_only_cloudflare_addresses(): void
    {
        $this->trust('cloudflare');

        $this->visit('173.245.48.10')->assertOk()->assertHeader('Strict-Transport-Security');
    }

    public function test_forwarded_headers_from_other_addresses_are_ignored(): void
    {
        $this->trust('cloudflare');

        $this->visit('203.0.113.9')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_nothing_is_trusted_by_default_and_forwarded_host_never_is(): void
    {
        $this->trust(null);
        $this->visit('173.245.48.10')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        // Even from a trusted proxy the school comes from the real Host header.
        $this->trust('*');
        $this->visit('10.0.0.1')->assertOk()->assertHeader('Strict-Transport-Security');
        $this->assertSame('proxy.'.config('tenancy.domain'), request()->getHost());
    }
}
