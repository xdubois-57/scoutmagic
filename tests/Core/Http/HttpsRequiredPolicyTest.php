<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Cookie\CookieConsentService;
use Core\Cookie\CookieHelper;
use Core\Http\Request;
use Core\Http\RequestScheme;
use Core\Http\Response;
use Core\Security\LastLoginMethodCookie;
use Core\Security\SessionManager;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * `https_required` (#751): while HTTPS is required, what PHP sees can no
 * longer strip `Secure` from a cookie nor HSTS from a response — the
 * shared host terminating TLS in front of PHP is the case this exists
 * for. With the explicit development exception, detection decides as
 * before.
 */
class HttpsRequiredPolicyTest extends TestCase
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
        // Process-wide statics: tests/bootstrap.php runs the suite with
        // the development exception, and every later test relies on it.
        RequestScheme::setHttpsRequired(false);
        RequestScheme::setTrustForwardedProto(false);
        CookieHelper::recordWith(null);
        unset($_COOKIE[LastLoginMethodCookie::NAME]);
        $_SERVER = $this->serverBackup;
    }

    /** What PHP sees behind a TLS terminator: plain HTTP, port 80. */
    private const INTERNAL_HTTP = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'unite.example'];

    public function testRequiredHttpsEnforcesProtectionsOnAnInternalHttpRequest(): void
    {
        RequestScheme::setHttpsRequired(true);

        $this->assertTrue(RequestScheme::httpsRequired());
        $this->assertTrue(RequestScheme::enforcesHttps(self::INTERNAL_HTTP));
        $this->assertFalse(
            RequestScheme::isHttps(self::INTERNAL_HTTP),
            'detection still reports what PHP perceived'
        );
        $this->assertTrue((new Request('GET', '/', [], [], [], self::INTERNAL_HTTP))->enforcesHttps());
    }

    public function testTheDevelopmentExceptionFallsBackToDetection(): void
    {
        RequestScheme::setHttpsRequired(false);

        $this->assertFalse(RequestScheme::enforcesHttps(self::INTERNAL_HTTP));
        $this->assertTrue(RequestScheme::enforcesHttps(['SERVER_PORT' => '443']));
    }

    public function testHstsIsSentWhenPhpSeesHttpAndHttpsIsRequired(): void
    {
        RequestScheme::setHttpsRequired(true);
        $_SERVER = self::INTERNAL_HTTP;

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            (new Response('test'))->getSecurityHeaders()['Strict-Transport-Security'] ?? null
        );
    }

    public function testSetHttpsFalseCannotRemoveHstsWhileHttpsIsRequired(): void
    {
        RequestScheme::setHttpsRequired(true);
        $_SERVER = self::INTERNAL_HTTP;

        $this->assertArrayHasKey(
            'Strict-Transport-Security',
            (new Response('test'))->setHttps(false)->getSecurityHeaders()
        );
    }

    public function testHstsIsAbsentOverHttpWithTheDevelopmentException(): void
    {
        RequestScheme::setHttpsRequired(false);
        $_SERVER = self::INTERNAL_HTTP;

        $this->assertArrayNotHasKey('Strict-Transport-Security', (new Response('test'))->getSecurityHeaders());
    }

    public function testTheConsentCookieIsSecureWhenPhpSeesHttpAndHttpsIsRequired(): void
    {
        RequestScheme::setHttpsRequired(true);
        $_SERVER = self::INTERNAL_HTTP;

        $service = new CookieConsentService([]);
        $service->acceptAll();

        $this->assertTrue($service->writtenCookieOptions('cookie_consent')['secure'] ?? null);
    }

    public function testTheConsentCookieIsNotSecureOverHttpWithTheDevelopmentException(): void
    {
        RequestScheme::setHttpsRequired(false);
        $_SERVER = self::INTERNAL_HTTP;

        $service = new CookieConsentService([]);
        $service->acceptAll();

        $this->assertFalse($service->writtenCookieOptions('cookie_consent')['secure'] ?? null);
    }

    public function testTheLastLoginMethodCookieIsSecureWhenPhpSeesHttpAndHttpsIsRequired(): void
    {
        RequestScheme::setHttpsRequired(true);
        $_SERVER = self::INTERNAL_HTTP;
        $recorded = [];
        CookieHelper::recordWith(static function (string $name, string $value, array $options) use (&$recorded): void {
            $recorded[$name] = $options;
        });

        LastLoginMethodCookie::remember(
            'password',
            new CookieConsentService(['cookie_consent' => '{"functional":true,"analytics":false}'])
        );

        $this->assertTrue($recorded[LastLoginMethodCookie::NAME]['secure'] ?? null);
    }

    /**
     * The session cookie is the one that matters most: its flag comes from
     * ini settings applied by session_start(), hence a process of its own.
     */
    #[RunInSeparateProcess]
    public function testTheSessionCookieIsSecureWhenPhpSeesHttpAndHttpsIsRequired(): void
    {
        RequestScheme::setHttpsRequired(true);
        $_SERVER = self::INTERNAL_HTTP;

        @SessionManager::start();

        $this->assertSame('1', ini_get('session.cookie_secure'));
    }

    #[RunInSeparateProcess]
    public function testTheSessionCookieIsNotSecureOverHttpWithTheDevelopmentException(): void
    {
        RequestScheme::setHttpsRequired(false);
        $_SERVER = self::INTERNAL_HTTP;

        @SessionManager::start();

        $this->assertSame('0', ini_get('session.cookie_secure'));
    }
}
