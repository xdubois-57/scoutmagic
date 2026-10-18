<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\FirstStepsService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The « Premiers pas » band's one fact (issue #718 § A): pending on the
 * site the wizard has just created, never on a site that predates it,
 * shown to superadmins only, and gone for all of them once one hides it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FirstStepsServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        FirstStepsService::register($this->settings);
    }

    public function testRegisterCreatesANonEditableSettingThatIsOff(): void
    {
        $row = (new SettingRepository($this->pdo))->findByModuleAndKey(null, FirstStepsService::SETTING_KEY);

        $this->assertNotNull($row);
        $this->assertSame('0', $row['setting_value']);
        $this->assertSame(0, (int) $row['editable']);
    }

    /**
     * An existing site gets the row at its next boot, with the default:
     * its superadmins have long done the three steps.
     */
    public function testASiteThatWasNeverMarkedShowsNothing(): void
    {
        $this->assertFalse((new FirstStepsService($this->settings))->shouldShowTo('superadmin'));
    }

    public function testAMarkedSiteShowsTheBandToSuperadminsOnly(): void
    {
        $service = new FirstStepsService($this->settings);
        $service->markPending();

        $this->assertTrue($service->shouldShowTo('superadmin'));
        foreach (['admin', 'chief', 'intendant', 'identified', 'public'] as $role) {
            $this->assertFalse($service->shouldShowTo($role), $role);
        }
    }

    public function testDismissingHidesItForEverySuperadmin(): void
    {
        $service = new FirstStepsService($this->settings);
        $service->markPending();
        $service->dismiss();

        // Read back through a fresh service: the next request's view.
        $nextRequest = new FirstStepsService(new SettingService(new SettingRepository($this->pdo)));
        $this->assertFalse($nextRequest->shouldShowTo('superadmin'));
    }

    /**
     * register() runs on every boot; it must never reset a pending band.
     */
    public function testRegisteringAgainKeepsThePendingValue(): void
    {
        (new FirstStepsService($this->settings))->markPending();

        $nextBoot = new SettingService(new SettingRepository($this->pdo));
        FirstStepsService::register($nextBoot);

        $this->assertTrue((new FirstStepsService($nextBoot))->shouldShowTo('superadmin'));
    }
}
