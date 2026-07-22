<?php

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieConsentService;
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
        // last_login_method (core) + calendar_view (module) are both 'functional'.
        $this->assertCount(2, $groups['functional']['cookies']);
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
}
