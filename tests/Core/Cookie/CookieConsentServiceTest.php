<?php

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieConsentService;
use Core\Cookie\CookieRegistry;
use PHPUnit\Framework\TestCase;

class CookieConsentServiceTest extends TestCase
{
    public function testHasConsentedReturnsFalseWhenNoCookieExists(): void
    {
        $service = new CookieConsentService([]);
        $this->assertFalse($service->hasConsented());
    }

    public function testHasConsentedReturnsTrueAfterSaveConsent(): void
    {
        $service = new CookieConsentService([]);
        $service->saveConsent(['functional' => true, 'analytics' => false]);
        $this->assertTrue($service->hasConsented());
    }

    public function testIsAllowedNecessaryAlwaysReturnsTrue(): void
    {
        $service = new CookieConsentService([]);
        $this->assertTrue($service->isAllowed('necessary'));
    }

    public function testIsAllowedNecessaryAlwaysReturnsTrueEvenWithoutConsent(): void
    {
        $service = new CookieConsentService([]);
        $this->assertTrue($service->isAllowed('necessary'));
    }

    public function testIsAllowedFunctionalReturnsFalseWhenNoConsentGiven(): void
    {
        $service = new CookieConsentService([]);
        $this->assertFalse($service->isAllowed('functional'));
    }

    public function testIsAllowedFunctionalReturnsTrueAfterAccepting(): void
    {
        $service = new CookieConsentService([]);
        $service->saveConsent(['functional' => true, 'analytics' => false]);
        $this->assertTrue($service->isAllowed('functional'));
    }

    public function testIsAllowedFunctionalReturnsFalseAfterRejecting(): void
    {
        $service = new CookieConsentService([]);
        $service->saveConsent(['functional' => false, 'analytics' => false]);
        $this->assertFalse($service->isAllowed('functional'));
    }

    public function testIsAllowedAnalyticsReturnsFalseWhenNoConsentGiven(): void
    {
        $service = new CookieConsentService([]);
        $this->assertFalse($service->isAllowed('analytics'));
    }

    public function testAcceptAllMakesAllCategoriesAllowed(): void
    {
        $service = new CookieConsentService([]);
        $service->acceptAll();
        $this->assertTrue($service->isAllowed('functional'));
        $this->assertTrue($service->isAllowed('analytics'));
    }

    public function testRejectAllMakesNonNecessaryCategoriesDisallowed(): void
    {
        $service = new CookieConsentService([]);
        $service->rejectAll();
        $this->assertTrue($service->isAllowed('necessary'));
        $this->assertFalse($service->isAllowed('functional'));
        $this->assertFalse($service->isAllowed('analytics'));
    }

    public function testGetConsentReturnsNullWhenNoChoiceMade(): void
    {
        $service = new CookieConsentService([]);
        $this->assertNull($service->getConsent());
    }

    public function testGetConsentReturnsCorrectStateAfterSaving(): void
    {
        $service = new CookieConsentService([]);
        $service->saveConsent(['functional' => true, 'analytics' => false]);
        $consent = $service->getConsent();
        $this->assertNotNull($consent);
        $this->assertTrue($consent['functional']);
        $this->assertFalse($consent['analytics']);
    }

    public function testGetAllDeclaredCookiesReturnsCoreCookiesGroupedByCategory(): void
    {
        $service = new CookieConsentService([]);
        $groups = $service->getAllDeclaredCookies();

        $this->assertArrayHasKey('necessary', $groups);
        $this->assertArrayHasKey('functional', $groups);
        $this->assertArrayHasKey('analytics', $groups);

        // Core cookies are mostly 'necessary', except last_login_method (functional).
        $this->assertNotEmpty($groups['necessary']['cookies']);
        $this->assertNotEmpty($groups['functional']['cookies']);
        $this->assertEmpty($groups['analytics']['cookies']);

        // Verify label and description exist
        $this->assertNotEmpty($groups['necessary']['label']);
        $this->assertNotEmpty($groups['necessary']['description']);
    }

    public function testRegisterModuleCookiesAddsCookiesToCorrectCategory(): void
    {
        $service = new CookieConsentService([]);
        $service->registerModuleCookies('calendar', [
            [
                'name' => 'calendar_view',
                'category' => 'functional',
                'purpose' => 'Mémorise le type d\'affichage choisi.',
                'duration' => '1 an',
            ],
        ]);

        $groups = $service->getAllDeclaredCookies();
        // last_login_method + content-{accountScope}-{version} +
        // theme_preference (core) + calendar_view (module) are all
        // 'functional'.
        $this->assertCount(4, $groups['functional']['cookies']);
        $names = array_column($groups['functional']['cookies'], 'name');
        $this->assertContains('calendar_view', $names);
    }

    public function testConsentReadFromExistingCookieJar(): void
    {
        $jar = ['cookie_consent' => '{"functional":true,"analytics":false}'];
        $service = new CookieConsentService($jar);

        $this->assertTrue($service->hasConsented());
        $this->assertTrue($service->isAllowed('functional'));
        $this->assertFalse($service->isAllowed('analytics'));
    }

    public function testCookieWasSetAfterSaving(): void
    {
        $service = new CookieConsentService([]);
        $this->assertFalse($service->wasCookieSet());
        $service->saveConsent(['functional' => true, 'analytics' => true]);
        $this->assertTrue($service->wasCookieSet());
    }

    /**
     * The thirteen months, as a duration actually written rather than as a
     * constant read back.
     *
     * `CONSENT_DURATION_DAYS` carries the rule in its own comment — « 13
     * months per ePrivacy directive » — and was held by nothing: the test
     * jar recorded the name and the value and dropped the attributes, so
     * the constant could be moved to thirty days or to ten years with
     * tests/Core/Cookie/ and tests/Core/View/ both green (issue #444). Ten
     * years is the one that matters: past the ceiling the directive sets,
     * and past the one this site prints on its own cookies page.
     *
     * A window rather than an instant, because `time()` can turn over
     * between the two calls, and asserting a single second would buy a
     * flake for nothing.
     */
    public function testTheConsentCookieLastsTheThirteenMonthsEPrivacyAllows(): void
    {
        $service = new CookieConsentService([]);

        $before = time();
        $service->acceptAll();
        $after = time();

        $options = $service->writtenCookieOptions('cookie_consent');
        $this->assertNotNull($options, 'accepting must write the consent cookie');

        $thirteenMonths = 395 * 86400;
        $this->assertGreaterThanOrEqual(
            $before + $thirteenMonths,
            $options['expires'],
            'a consent shorter than thirteen months asks the visitor again too soon'
        );
        $this->assertLessThanOrEqual(
            $after + $thirteenMonths,
            $options['expires'],
            'a consent longer than thirteen months is past the ePrivacy ceiling this site announces'
        );
    }

    /**
     * And the other half: the number the visitor is shown is the number
     * that is applied.
     *
     * Asserting the expiry alone would leave the cookies page free to
     * announce anything at all. These two are one rule — the directive
     * bounds the duration AND requires it to be stated — so they are held
     * against each other rather than each against a literal of its own.
     */
    public function testTheDurationOnTheCookiesPageIsTheOneApplied(): void
    {
        $service = new CookieConsentService([]);
        $service->acceptAll();

        $options = $service->writtenCookieOptions('cookie_consent');
        $this->assertNotNull($options);

        $days = (int) round(($options['expires'] - time()) / 86400);
        $months = (int) round($days / 30.4375);

        $declared = null;
        foreach (CookieRegistry::getCoreCookies() as $cookie) {
            if ($cookie['name'] === 'cookie_consent') {
                $declared = $cookie['duration'];
            }
        }

        $this->assertNotNull($declared, 'the consent cookie must be declared on the cookies page');
        $this->assertSame(
            $months . ' mois',
            $declared,
            'the cookies page announces a duration the service does not apply'
        );
    }
}
