<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use Core\Http\RequestScheme;
use Core\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The single HTTPS-detection helper, and the two properties that made it
 * necessary: HSTS and the cookie `Secure` flag must agree in every case,
 * and `X-Forwarded-Proto` must be inert unless the deployment opted in.
 */
class RequestSchemeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        RequestScheme::setTrustForwardedProto(false);
    }

    protected function tearDown(): void
    {
        // Process-wide static: leaving it on would silently change every
        // later test in the run.
        RequestScheme::setTrustForwardedProto(false);
        $_SERVER = $this->serverBackup;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function request(array $server): Request
    {
        return new Request('GET', '/', [], [], [], $server);
    }

    public function testPlainHttpIsNotHttps(): void
    {
        $this->assertFalse(RequestScheme::isHttps(['SERVER_PORT' => '80']));
    }

    public function testHttpsServerVariableOnMeansHttps(): void
    {
        $this->assertTrue(RequestScheme::isHttps(['HTTPS' => 'on', 'SERVER_PORT' => '8443']));
    }

    public function testHttpsServerVariableOffMeansPlainHttp(): void
    {
        $this->assertFalse(RequestScheme::isHttps(['HTTPS' => 'off', 'SERVER_PORT' => '80']));
    }

    /**
     * IIS is the reason the "off" comparison exists at all; some builds
     * capitalise it, which the pre-convergence call sites read as "on".
     */
    public function testHttpsServerVariableOffIsCaseInsensitive(): void
    {
        $this->assertFalse(RequestScheme::isHttps(['HTTPS' => 'Off', 'SERVER_PORT' => '80']));
    }

    public function testEmptyHttpsServerVariableMeansPlainHttp(): void
    {
        $this->assertFalse(RequestScheme::isHttps(['HTTPS' => '', 'SERVER_PORT' => '80']));
    }

    public function testServerPort443MeansHttps(): void
    {
        $this->assertTrue(RequestScheme::isHttps(['SERVER_PORT' => '443']));
    }

    public function testForwardedProtoIsIgnoredWhenTheOptInIsOff(): void
    {
        $this->assertFalse(RequestScheme::isHttps([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'SERVER_PORT' => '80',
        ]));
    }

    public function testForwardedProtoIsHonouredWhenTheOptInIsOn(): void
    {
        RequestScheme::setTrustForwardedProto(true);

        $this->assertTrue(RequestScheme::isHttps([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'SERVER_PORT' => '80',
        ]));
    }

    public function testForwardedProtoHttpStaysPlainHttpEvenWithTheOptInOn(): void
    {
        RequestScheme::setTrustForwardedProto(true);

        $this->assertFalse(RequestScheme::isHttps([
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'SERVER_PORT' => '80',
        ]));
    }

    /**
     * A chain of proxies appends to the header; the leftmost value is the
     * scheme the browser actually spoke.
     */
    public function testForwardedProtoReadsTheLeftmostValueOfAChain(): void
    {
        RequestScheme::setTrustForwardedProto(true);

        $this->assertTrue(RequestScheme::isHttps([
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
            'SERVER_PORT' => '80',
        ]));
        $this->assertFalse(RequestScheme::isHttps([
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
            'SERVER_PORT' => '80',
        ]));
    }

    /**
     * The header can only ever upgrade the verdict — it never contradicts
     * a SAPI that already reports an encrypted connection.
     */
    public function testForwardedProtoNeverDowngradesARealTlsConnection(): void
    {
        RequestScheme::setTrustForwardedProto(true);

        $this->assertTrue(RequestScheme::isHttps([
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ]));
    }

    public function testNoOtherProxyHeaderIsTrusted(): void
    {
        RequestScheme::setTrustForwardedProto(true);

        $this->assertFalse(RequestScheme::isHttps([
            'HTTP_X_FORWARDED_SSL' => 'on',
            'HTTP_FORWARDED' => 'proto=https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'SERVER_PORT' => '80',
        ]));
    }

    public function testTrustsForwardedProtoReportsTheConfiguredValue(): void
    {
        $this->assertFalse(RequestScheme::trustsForwardedProto());

        RequestScheme::setTrustForwardedProto(true);

        $this->assertTrue(RequestScheme::trustsForwardedProto());
    }

    public function testRequestDelegatesToTheSameHelper(): void
    {
        $this->assertFalse($this->request(['HTTP_X_FORWARDED_PROTO' => 'https'])->isHttps());

        RequestScheme::setTrustForwardedProto(true);

        $this->assertTrue($this->request(['HTTP_X_FORWARDED_PROTO' => 'https'])->isHttps());
        $this->assertTrue($this->request(['HTTPS' => 'on'])->isHttps());
        $this->assertTrue($this->request(['SERVER_PORT' => '443'])->isHttps());
    }

    /**
     * The divergence this iteration exists to remove: HSTS used to look at
     * $_SERVER['HTTPS'] alone, so a host where only SERVER_PORT says 443
     * got Secure session cookies and no Strict-Transport-Security header.
     */
    public function testHstsIsEmittedOnAPort443HostLikeTheCookieFlagAlreadyWas(): void
    {
        $_SERVER = ['SERVER_PORT' => '443'];

        $headers = (new Response('test'))->getSecurityHeaders();

        $this->assertArrayHasKey('Strict-Transport-Security', $headers);
        $this->assertTrue(RequestScheme::isHttps($_SERVER), 'cookie Secure flag and HSTS must agree');
    }

    public function testHstsIsNotEmittedOnAForwardedProtoHostWithoutTheOptIn(): void
    {
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https', 'SERVER_PORT' => '80'];

        $this->assertArrayNotHasKey(
            'Strict-Transport-Security',
            (new Response('test'))->getSecurityHeaders()
        );
    }

    public function testHstsIsEmittedOnAForwardedProtoHostWithTheOptIn(): void
    {
        RequestScheme::setTrustForwardedProto(true);
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https', 'SERVER_PORT' => '80'];

        $this->assertArrayHasKey(
            'Strict-Transport-Security',
            (new Response('test'))->getSecurityHeaders()
        );
    }

    /**
     * setHttps() is a caller stating the scheme outright; it must keep
     * winning over anything the helper would derive.
     */
    public function testForceHttpsOverridesDetectionInBothDirections(): void
    {
        $_SERVER = ['SERVER_PORT' => '443', 'HTTPS' => 'on'];
        $this->assertArrayNotHasKey(
            'Strict-Transport-Security',
            (new Response('test'))->setHttps(false)->getSecurityHeaders()
        );

        $_SERVER = ['SERVER_PORT' => '80'];
        $this->assertArrayHasKey(
            'Strict-Transport-Security',
            (new Response('test'))->setHttps(true)->getSecurityHeaders()
        );
    }
}
