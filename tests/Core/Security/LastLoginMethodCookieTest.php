<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Cookie\CookieConsentService;
use Core\Security\LastLoginMethodCookie;
use PHPUnit\Framework\TestCase;

class LastLoginMethodCookieTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[LastLoginMethodCookie::NAME]);
    }

    public function testReadReturnsNullWhenNoCookieSet(): void
    {
        unset($_COOKIE[LastLoginMethodCookie::NAME]);
        $this->assertNull(LastLoginMethodCookie::read());
    }

    public function testReadReturnsTheStoredMethod(): void
    {
        $_COOKIE[LastLoginMethodCookie::NAME] = 'password';
        $this->assertSame('password', LastLoginMethodCookie::read());
    }

    public function testReadRejectsATamperedOrUnknownValue(): void
    {
        $_COOKIE[LastLoginMethodCookie::NAME] = 'not-a-real-method';
        $this->assertNull(LastLoginMethodCookie::read());
    }

    public function testForgetRemovesTheCookieValue(): void
    {
        $_COOKIE[LastLoginMethodCookie::NAME] = 'passkey';
        LastLoginMethodCookie::forget();
        $this->assertNull(LastLoginMethodCookie::read());
    }

    public function testRememberDoesNothingForAnUnrecognizedMethod(): void
    {
        // No consent service call at all for a bogus value — nothing to catch/throw.
        $consentService = new CookieConsentService(['cookie_consent' => '{"functional":true,"analytics":false}']);
        LastLoginMethodCookie::remember('not-a-real-method', $consentService);
        $this->assertNull(LastLoginMethodCookie::read());
    }

    public function testRememberNeverThrowsWhenFunctionalCookiesAreNotConsented(): void
    {
        $consentService = new CookieConsentService(['cookie_consent' => '{"functional":false,"analytics":false}']);

        // Module addendum: must never block/fail a login over this — the
        // CookieConsentException from Core\Cookie\CookieHelper is caught internally.
        LastLoginMethodCookie::remember('password', $consentService);
        $this->addToAssertionCount(1); // reaching here means no exception propagated
    }
}
