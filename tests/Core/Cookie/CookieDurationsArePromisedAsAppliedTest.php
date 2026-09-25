<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieConsentService;
use Core\Cookie\CookieHelper;
use Core\Cookie\CookieRegistry;
use Core\Security\LastLoginMethodCookie;
use PHPUnit\Framework\TestCase;

/**
 * The cookie preferences page announces a duration for each cookie. This
 * holds the site to it.
 *
 * ONE check for the whole page rather than one per cookie, which is what
 * #515 asks for: two twin tests would each be right about their own
 * cookie and say nothing about the next one added. The page is a promise
 * made as a whole, so it is held as a whole.
 *
 * What made this necessary is that the promise was unobservable. The
 * thirteen months lived in three places — the French wording here, a
 * private `395` in `CookieConsentService`, another in
 * `LastLoginMethodCookie` — and `setcookie()` offers no seam, so:
 *
 * ```
 * perl -0pi -e 's/EXPIRY_DAYS = 395;/EXPIRY_DAYS = 3650;/' core/Security/LastLoginMethodCookie.php
 * vendor/bin/phpunit tests/Core/Security/ tests/Core/Cookie/    # vert
 * ```
 *
 * Ten years announced as thirteen months, with the suite green (#444 for
 * the consent cookie, #515 for this one).
 *
 * The lifetimes are now read from `CookieRegistry` by both writers, so
 * the three copies are one. That alone makes drift impossible for these
 * two cookies — but it does not make the NEXT cookie honest, which is
 * what the walk below is for.
 */
final class CookieDurationsArePromisedAsAppliedTest extends TestCase
{
    protected function tearDown(): void
    {
        CookieHelper::recordWith(null);
        parent::tearDown();
    }

    /**
     * Every declared lifetime is written in words the page actually shows.
     */
    public function testEveryDeclaredLifetimeHasItsWordingAndEveryWordingItsLifetime(): void
    {
        $withALifetime = array_filter(
            CookieRegistry::getCoreCookies(),
            static fn(array $cookie): bool => isset($cookie['max_age_days'])
        );

        $this->assertNotSame(
            [],
            $withALifetime,
            'no cookie declares a lifetime any more, so the walk below checks nothing'
        );

        foreach ($withALifetime as $cookie) {
            $days = $cookie['max_age_days'];

            $this->assertArrayHasKey(
                $days,
                CookieRegistry::DECLARED_DURATIONS,
                "« {$cookie['name']} » lasts {$days} days and no French wording says so"
            );

            $this->assertSame(
                CookieRegistry::DECLARED_DURATIONS[$days],
                $cookie['duration'],
                "the preferences page announces « {$cookie['duration']} » for « {$cookie['name']} », "
                    . "which is not how {$days} days is written"
            );
        }
    }

    /**
     * `last_login_method` is written with the duration the page announces.
     *
     * Driven through the real writer — `LastLoginMethodCookie::remember()`
     * calls `CookieHelper::set()`, which assembles what `setcookie()`
     * would receive — so what is asserted is what a browser gets.
     */
    public function testTheLoginMethodCookieCarriesTheDurationThePageAnnounces(): void
    {
        $applied = null;
        CookieHelper::recordWith(
            static function (string $name, string $value, array $options) use (&$applied): void {
                $applied = $options;
            }
        );

        // Functional consent given, because `remember()` is best-effort and
        // silently writes nothing without it — a missing cookie would pass
        // this test for the wrong reason, which the assertion below refuses.
        $consented = new CookieConsentService([
            'cookie_consent' => json_encode(['functional' => true, 'analytics' => false]),
        ]);

        $before = time();
        LastLoginMethodCookie::remember('passkey', $consented);
        $after = time();

        $this->assertNotNull($applied, 'the cookie was not written at all, so its duration proves nothing');

        $expected = $this->expectedDaysFor(LastLoginMethodCookie::NAME);

        // A second may pass between the two clock reads; the window is the
        // assertion, not a fixed instant.
        $this->assertGreaterThanOrEqual($before + $expected * 86400, $applied['expires']);
        $this->assertLessThanOrEqual($after + $expected * 86400, $applied['expires']);
    }

    /**
     * And the consent cookie, through its own seam (#444's).
     */
    public function testTheConsentCookieCarriesTheDurationThePageAnnounces(): void
    {
        $service = new CookieConsentService([]);
        $service->acceptAll();

        $options = $service->writtenCookieOptions('cookie_consent');
        $this->assertNotNull($options, 'the consent cookie was not written, so its duration proves nothing');

        $expected = $this->expectedDaysFor('cookie_consent');
        $this->assertEqualsWithDelta(time() + $expected * 86400, $options['expires'], 5);
    }

    /**
     * What the page promises for one cookie, in days.
     */
    private function expectedDaysFor(string $name): int
    {
        foreach (CookieRegistry::getCoreCookies() as $cookie) {
            if ($cookie['name'] === $name) {
                $this->assertArrayHasKey(
                    'max_age_days',
                    $cookie,
                    "« {$name} » is written with a fixed lifetime and does not declare one"
                );

                return $cookie['max_age_days'];
            }
        }

        $this->fail("« {$name} » is not declared in CookieRegistry at all.");
    }
}
