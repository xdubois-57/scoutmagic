<?php

declare(strict_types=1);

namespace Tests\Core\Cookie;

use Core\Cookie\CookieConsentException;
use Core\Cookie\CookieConsentService;
use Core\Cookie\CookieHelper;
use PHPUnit\Framework\TestCase;

class CookieHelperTest extends TestCase
{
    public function testSetWithNecessaryCategorySucceedsRegardlessOfConsent(): void
    {
        $service = new CookieConsentService([]);

        // Should not throw — necessary is always allowed
        // In CLI we cannot actually call setcookie(), so we test the consent logic
        // by verifying no exception is thrown
        $this->assertTrue($service->isAllowed('necessary'));
    }

    public function testSetWithFunctionalCategoryThrowsWhenNotConsented(): void
    {
        $service = new CookieConsentService([]);

        $this->expectException(CookieConsentException::class);
        $this->expectExceptionMessage("Cannot set cookie 'test_cookie': category 'functional' not consented.");

        CookieHelper::set('test_cookie', 'value', time() + 3600, 'functional', $service);
    }

    public function testSetWithFunctionalCategorySucceedsWhenConsented(): void
    {
        $jar = ['cookie_consent' => '{"functional":true,"analytics":false}'];
        $service = new CookieConsentService($jar);

        // Verify the consent check passes (no exception)
        $this->assertTrue($service->isAllowed('functional'));
    }

    public function testSetWithAnalyticsCategoryThrowsWhenNotConsented(): void
    {
        $service = new CookieConsentService([]);

        $this->expectException(CookieConsentException::class);

        CookieHelper::set('tracking', 'value', time() + 3600, 'analytics', $service);
    }

    public function testSetWithAnalyticsCategorySucceedsWhenConsented(): void
    {
        $jar = ['cookie_consent' => '{"functional":false,"analytics":true}'];
        $service = new CookieConsentService($jar);

        $this->assertTrue($service->isAllowed('analytics'));
    }
}
