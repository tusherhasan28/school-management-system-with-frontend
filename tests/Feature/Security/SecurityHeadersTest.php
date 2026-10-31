<?php

namespace Tests\Feature\Security;

use Tests\TenantTestCase;

class SecurityHeadersTest extends TenantTestCase
{
    public function test_pages_send_security_headers_and_a_matching_script_nonce(): void
    {
        foreach (['/', '/login'] as $path) {
            $response = $this->get($path)->assertOk();
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringContainsString("frame-ancestors 'self'", $csp);
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
            preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);

            // Every inline executable script carries this request's nonce (JSON data blocks are not executed).
            preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/', $response->getContent(), $scripts);
            foreach (array_filter($scripts[1], fn ($a) => ! str_contains($a, 'application/json') && ! str_contains($a, 'application/ld+json')) as $attributes) {
                $this->assertStringContainsString('nonce="'.$m[1].'"', $attributes, "inline script without nonce on {$path}: <script{$attributes}>");
            }
        }

        $this->assertStringNotContainsString("'unsafe-inline'", explode('; ', (string) $this->get('/login')->headers->get('Content-Security-Policy'))[1]);
    }

    public function test_hsts_only_on_https(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get(str_replace('http://', 'https://', $this->schoolUrl($this->school, '/login')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_appearance_cookie_cannot_inject_script(): void
    {
        $html = $this->withUnencryptedCookie('appearance', "';alert(1)//")->get('/login')->getContent();
        $this->assertStringContainsString("const appearance = 'system';", $html);
    }
}
