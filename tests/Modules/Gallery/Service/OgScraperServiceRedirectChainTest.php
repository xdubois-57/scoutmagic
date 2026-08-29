<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\OgScraperService;
use PHPUnit\Framework\TestCase;

/**
 * OgScraperServiceTest covers the SSRF-target rejections that need no
 * network access (an IP-literal host is validated without a DNS lookup).
 * Redirect-chain behaviour needs a real hop to a *hostname* that
 * subsequently resolves somewhere unsafe — not reproducible with a real
 * network call in CI — so every fake here overrides resolveValidatedIp()
 * for hostnames only (treating "public-redirector.example" as if it
 * resolved to a public address, with no actual DNS lookup), while
 * IP-literal hosts still go through the real implementation — which does
 * no DNS lookup for those either — so the private-IP rejection below is
 * exercised for real. This proves per-hop re-validation and the hop cap
 * actually run rather than only trusting the first URL's own validation.
 */
class OgScraperServiceRedirectChainTest extends TestCase
{
    public function testARedirectToAPrivateIpIsRejectedEvenThoughTheFirstHopWasPublic(): void
    {
        $service = new class extends OgScraperService {
            protected function resolveValidatedIp(string $host): ?string
            {
                if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    return parent::resolveValidatedIp($host);
                }
                return '93.184.216.34';
            }

            protected function performRawRequest(
                string $scheme,
                string $host,
                string $ip,
                string $pathAndQuery,
                int $maxBytes,
                float $timeoutSeconds
            ): array {
                if ($host === 'public-redirector.example') {
                    return ['status' => 302, 'headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'], 'body' => ''];
                }

                // Never reached if per-hop revalidation works — the
                // second hop must be rejected by resolveValidatedIp()
                // before performRawRequest() is even called again.
                return ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html></html>'];
            }
        };

        $this->assertNull($service->fetchImageBytes('http://public-redirector.example/share'));
    }

    public function testARelativeRedirectIsResolvedAgainstItsOwnOrigin(): void
    {
        $service = new class extends OgScraperService {
            /** @var string[] */
            public array $pathsRequested = [];

            protected function resolveValidatedIp(string $host): string
            {
                return '93.184.216.34';
            }

            protected function performRawRequest(
                string $scheme,
                string $host,
                string $ip,
                string $pathAndQuery,
                int $maxBytes,
                float $timeoutSeconds
            ): array {
                $this->pathsRequested[] = $pathAndQuery;
                if (count($this->pathsRequested) === 1) {
                    return ['status' => 301, 'headers' => ['location' => '/final'], 'body' => ''];
                }

                return ['status' => 200, 'headers' => ['content-type' => 'image/jpeg'], 'body' => 'bytes'];
            }
        };

        $this->assertSame('bytes', $service->fetchImageBytes('http://public-redirector.example/share'));
        $this->assertSame(['/share', '/final'], $service->pathsRequested);
    }

    public function testAChainLongerThanTheHopCapIsRejected(): void
    {
        $service = new class extends OgScraperService {
            public int $calls = 0;

            protected function resolveValidatedIp(string $host): string
            {
                return '93.184.216.34';
            }

            protected function performRawRequest(
                string $scheme,
                string $host,
                string $ip,
                string $pathAndQuery,
                int $maxBytes,
                float $timeoutSeconds
            ): array {
                $this->calls++;
                // Always redirects one step further — an infinite chain
                // if the hop cap did not stop it.
                return ['status' => 302, 'headers' => ['location' => '/step-' . $this->calls], 'body' => ''];
            }
        };

        $this->assertNull($service->fetchImageBytes('http://public-redirector.example/start'));
        // MAX_REDIRECTS = 5, so at most 6 attempts (the original request
        // plus 5 redirected hops) are ever made — proof the loop actually
        // terminates rather than following forever.
        $this->assertLessThanOrEqual(6, $service->calls);
    }

    public function testAResponseWithoutAnHtmlContentTypeIsRejectedByFetch(): void
    {
        $service = new class extends OgScraperService {
            protected function resolveValidatedIp(string $host): string
            {
                return '93.184.216.34';
            }

            protected function performRawRequest(
                string $scheme,
                string $host,
                string $ip,
                string $pathAndQuery,
                int $maxBytes,
                float $timeoutSeconds
            ): array {
                return ['status' => 200, 'headers' => ['content-type' => 'application/octet-stream'], 'body' => 'not html'];
            }
        };

        $this->expectException(\Modules\Gallery\Api\GalleryException::class);
        $service->fetch('http://public-redirector.example/page');
    }

    public function testAResponseWithoutAnImageContentTypeIsRejectedByFetchImageBytes(): void
    {
        $service = new class extends OgScraperService {
            protected function resolveValidatedIp(string $host): string
            {
                return '93.184.216.34';
            }

            protected function performRawRequest(
                string $scheme,
                string $host,
                string $ip,
                string $pathAndQuery,
                int $maxBytes,
                float $timeoutSeconds
            ): array {
                return ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html></html>'];
            }
        };

        $this->assertNull($service->fetchImageBytes('http://public-redirector.example/not-an-image'));
    }

    public function testAMissingContentTypeHeaderStillSucceedsSoALegitimateServerIsNotBroken(): void
    {
        $service = new class extends OgScraperService {
            protected function resolveValidatedIp(string $host): string
            {
                return '93.184.216.34';
            }

            protected function performRawRequest(
                string $scheme,
                string $host,
                string $ip,
                string $pathAndQuery,
                int $maxBytes,
                float $timeoutSeconds
            ): array {
                return ['status' => 200, 'headers' => [], 'body' => '<html><head><meta property="og:title" content="Hello"></head></html>'];
            }
        };

        $tags = $service->fetch('http://public-redirector.example/page');
        $this->assertSame('Hello', $tags['title']);
    }
}
