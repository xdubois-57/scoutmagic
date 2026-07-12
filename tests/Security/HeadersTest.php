<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Http\Response;
use PHPUnit\Framework\TestCase;

class HeadersTest extends TestCase
{
    public function testAllSecurityHeadersPresent(): void
    {
        $response = new Response('test');
        $headers = $response->getSecurityHeaders();

        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);

        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertSame('DENY', $headers['X-Frame-Options']);

        $this->assertArrayHasKey('Referrer-Policy', $headers);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);

        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertArrayHasKey('Permissions-Policy', $headers);
        $this->assertSame('camera=(), microphone=(), geolocation=()', $headers['Permissions-Policy']);
    }

    public function testCspIsRestrictive(): void
    {
        $response = new Response('test');
        $csp = $response->getSecurityHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function testCspIncludesNonceWhenSet(): void
    {
        $response = new Response('test');
        $response->setCspNonce('abc123');
        $csp = $response->getSecurityHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("'nonce-abc123'", $csp);
    }

    public function testHstsAbsentOverHttp(): void
    {
        $response = new Response('test');
        $response->setHttps(false);
        $headers = $response->getSecurityHeaders();

        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testHstsPresentOverHttps(): void
    {
        $response = new Response('test');
        $response->setHttps(true);
        $headers = $response->getSecurityHeaders();

        $this->assertArrayHasKey('Strict-Transport-Security', $headers);
        $this->assertStringContainsString('max-age=31536000', $headers['Strict-Transport-Security']);
        $this->assertStringContainsString('includeSubDomains', $headers['Strict-Transport-Security']);
    }
}
