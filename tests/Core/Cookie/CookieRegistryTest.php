<?php

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieRegistry;
use PHPUnit\Framework\TestCase;

class CookieRegistryTest extends TestCase
{
    public function testGetCoreCookiesReturnsTenCookies(): void
    {
        // Six historical entries, plus theme_preference (the dark-mode
        // choice, functional, stored client-side), offline-config (the
        // third Cache Storage entry, issue #233), sm_display (the
        // installed application's display mode, issue #502) and
        // scoutmagic_setup_proof (the installer's token proof, #719).
        $cookies = CookieRegistry::getCoreCookies();
        $this->assertCount(10, $cookies);
    }

    /**
     * #719: the installer's proof that the token was typed. Strictly
     * necessary — the install cannot go on without it — and declared with
     * the very lifetime Core\Security\BootstrapHandoff gives it.
     */
    public function testTheInstallersTokenProofIsDeclared(): void
    {
        $byName = array_column(CookieRegistry::getCoreCookies(), null, 'name');

        $this->assertArrayHasKey(\Core\Security\BootstrapHandoff::PROOF_COOKIE, $byName);
        $proof = $byName[\Core\Security\BootstrapHandoff::PROOF_COOKIE];
        $this->assertSame('necessary', $proof['category']);
        $this->assertSame(7200, \Core\Security\BootstrapHandoff::PROOF_LIFETIME_SECONDS);
        $this->assertSame('2 heures', $proof['duration']);
    }

    /**
     * Issue #502: public/assets/js/display-mode.js writes it on every page
     * of the installed application. Strictly necessary — without it, a
     * file opened from the app strands the window — so it must not wait
     * for a consent a visitor may never give.
     */
    public function testTheDisplayModeCookieIsDeclaredStrictlyNecessary(): void
    {
        $byName = array_column(CookieRegistry::getCoreCookies(), null, 'name');

        $this->assertArrayHasKey('sm_display', $byName);
        $this->assertSame('necessary', $byName['sm_display']['category']);
        $this->assertStringContainsString(
            "'sm_display'",
            (string) file_get_contents(dirname(__DIR__, 3) . '/core/File/Held/InstalledAppFileInterceptor.php'),
            'The cookie the registry declares is not the one the server reads.'
        );
        $this->assertStringContainsString(
            "'sm_display'",
            (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/display-mode.js'),
            'The cookie the registry declares is not the one the page writes.'
        );
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
