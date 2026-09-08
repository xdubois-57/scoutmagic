<?php

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieRegistry;
use PHPUnit\Framework\TestCase;

class CookieRegistryTest extends TestCase
{
    public function testGetCoreCookiesReturnsEightCookies(): void
    {
        // Six historical entries, plus theme_preference (the dark-mode
        // choice, functional, stored client-side) and offline-config
        // (the third Cache Storage entry, issue #233).
        $cookies = CookieRegistry::getCoreCookies();
        $this->assertCount(8, $cookies);
    }

    /**
     * Issue #233: the preferences page claims to be a COMPLETE picture of
     * the site's local storage, and public/sw.js writes three Cache
     * Storage entries. Two were declared and the third was not, so a page
     * that promised exhaustiveness listed nine lines while eleven things
     * were stored.
     */
    public function testTheOfflineConfigCacheIsDeclared(): void
    {
        $names = array_column(CookieRegistry::getCoreCookies(), 'name');

        $this->assertContains('offline-config', $names);
        $this->assertContains('app-shell-{version}', $names);
        $this->assertContains('content-{accountScope}-{version}', $names);
    }

    public function testEachCookieHasRequiredKeys(): void
    {
        $cookies = CookieRegistry::getCoreCookies();

        foreach ($cookies as $cookie) {
            $this->assertArrayHasKey('name', $cookie);
            $this->assertArrayHasKey('category', $cookie);
            $this->assertArrayHasKey('purpose', $cookie);
            $this->assertArrayHasKey('duration', $cookie);
        }
    }

    public function testAllCookiesHaveNonEmptyPurposeAndDuration(): void
    {
        $cookies = CookieRegistry::getCoreCookies();

        foreach ($cookies as $cookie) {
            $this->assertNotEmpty($cookie['purpose'], "Cookie '{$cookie['name']}' has empty purpose.");
            $this->assertNotEmpty($cookie['duration'], "Cookie '{$cookie['name']}' has empty duration.");
        }
    }

    public function testCookieNamesAreCorrect(): void
    {
        $cookies = CookieRegistry::getCoreCookies();
        $names = array_column($cookies, 'name');

        $this->assertContains('PHPSESSID', $names);
        $this->assertContains('_csrf_token', $names);
        $this->assertContains('cookie_consent', $names);
        $this->assertContains('last_login_method', $names);
    }

    public function testMostCoreCookiesAreNecessaryExceptTheFunctionalOnes(): void
    {
        $cookies = CookieRegistry::getCoreCookies();
        // offline-config is deliberately NOT here: it holds the
        // configuration the worker reads to decide whether caching is
        // allowed at all, so gating it on that same decision would leave
        // nothing to read.
        $functionalNames = ['last_login_method', 'content-{accountScope}-{version}', 'theme_preference'];

        foreach ($cookies as $cookie) {
            $expected = in_array($cookie['name'], $functionalNames, true) ? 'functional' : 'necessary';
            $this->assertSame($expected, $cookie['category'], "Cookie '{$cookie['name']}' has unexpected category.");
        }
    }
}
