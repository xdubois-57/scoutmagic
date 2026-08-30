<?php

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieRegistry;
use PHPUnit\Framework\TestCase;

class CookieRegistryTest extends TestCase
{
    public function testGetCoreCookiesReturnsEightCookies(): void
    {
        // Six historical entries plus the two client-side, functional
        // localStorage keys: theme_preference (the dark-mode choice) and
        // camps_map_collapsed (the camps map's fold).
        $cookies = CookieRegistry::getCoreCookies();
        $this->assertCount(8, $cookies);
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
        $functionalNames = ['last_login_method', 'content-{accountScope}-{version}', 'theme_preference', 'camps_map_collapsed'];

        foreach ($cookies as $cookie) {
            $expected = in_array($cookie['name'], $functionalNames, true) ? 'functional' : 'necessary';
            $this->assertSame($expected, $cookie['category'], "Cookie '{$cookie['name']}' has unexpected category.");
        }
    }
}
