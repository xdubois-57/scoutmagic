<?php

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieRegistry;
use PHPUnit\Framework\TestCase;

class CookieRegistryTest extends TestCase
{
    public function testGetCoreCookiesReturnsFourCookies(): void
    {
        $cookies = CookieRegistry::getCoreCookies();
        $this->assertCount(4, $cookies);
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

    public function testMostCoreCookiesAreNecessaryExceptLastLoginMethod(): void
    {
        $cookies = CookieRegistry::getCoreCookies();

        foreach ($cookies as $cookie) {
            $expected = $cookie['name'] === 'last_login_method' ? 'functional' : 'necessary';
            $this->assertSame($expected, $cookie['category'], "Cookie '{$cookie['name']}' has unexpected category.");
        }
    }
}
