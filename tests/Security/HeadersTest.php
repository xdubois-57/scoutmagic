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
        // style-src keeps 'unsafe-inline' as the FALLBACK for browsers that
        // do not know the split directives — see the dedicated test below
        // for the property that actually matters.
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    /**
     * The one property the split of style-src exists to create: an
     * inline `<style>` ELEMENT is not allowed by anything but a nonce,
     * while a `style="…"` ATTRIBUTE still is.
     *
     * An injected style element restyles the whole page — overlays over
     * arbitrary controls, attribute-selector rules whose background URL
     * leaks what they matched. An injected attribute reaches one element
     * the injection already controls. Those are not the same risk, and
     * `style-src` alone could not tell them apart.
     */
    public function testStyleElementsAreNonceOnlyWhileAttributesStayInline(): void
    {
        $csp = (new Response('test'))->setCspNonce('abc123')->getSecurityHeaders()['Content-Security-Policy'];

        $this->assertStringContainsString("style-src-elem 'self' 'nonce-abc123'", $csp);
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $csp);

        $elem = $this->directive($csp, 'style-src-elem');
        $this->assertStringNotContainsString("'unsafe-inline'", $elem);
    }

    /**
     * Without a nonce there is no way to authorise an inline `<style>`,
     * so style-src-elem must still refuse them rather than silently fall
     * back to allowing everything. This is the shape the 413 page and
     * any other nonce-less Response emits.
     */
    public function testStyleElementsStayRefusedWhenNoNonceExists(): void
    {
        $csp = (new Response('test'))->getSecurityHeaders()['Content-Security-Policy'];

        $this->assertSame("style-src-elem 'self'", $this->directive($csp, 'style-src-elem'));
    }

    /**
     * The fallback is load-bearing, not decoration: a browser that does
     * not know `style-src-attr` (Chrome < 75, Firefox < 108, Safari <
     * 15.4) ignores it and reads `style-src` instead. Drop
     * 'unsafe-inline' there and every inline style attribute on the site
     * is blocked on those browsers — the layout breaks, on real devices
     * that are still in use.
     */
    public function testTheFallbackDirectiveStillPermitsInlineForOlderBrowsers(): void
    {
        $csp = (new Response('test'))->getSecurityHeaders()['Content-Security-Policy'];

        $this->assertSame("style-src 'self' 'unsafe-inline'", $this->directive($csp, 'style-src'));
    }

    /**
     * Reads one directive out of a policy by exact name — `style-src`
     * must not match `style-src-elem`, which a substring check would.
     */
    private function directive(string $csp, string $name): string
    {
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);
            if ($directive === $name || str_starts_with($directive, $name . ' ')) {
                return $directive;
            }
        }

        $this->fail("the policy carries no {$name} directive: {$csp}");
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
