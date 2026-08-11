<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearTransitionVetoedException;
use Modules\Registration\Api\ScoutYearTransitionVetoProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ScoutYearAdminServiceTest extends TestCase
{
    private SettingService $settingService;
    private ScoutYearAdminService $adminService;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($pdo));
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id', null, '^[0-9]+$', null, false);
        $this->adminService = new ScoutYearAdminService($this->settingService);
    }

    public function testActivateStaffYearSetsSetting(): void
    {
        $this->adminService->activateStaffYear(5);

        $this->assertSame('5', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testDeactivateStaffYearClearsSetting(): void
    {
        $this->adminService->activateStaffYear(5);
        $this->adminService->deactivateStaffYear();

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testActivatePublicYearSetsCurrentAndClearsStaff(): void
    {
        $this->adminService->activateStaffYear(7);
        $this->adminService->activatePublicYear(9);

        $this->assertSame('9', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    /**
     * §8.38 — no veto provider wired (registration module absent/disabled):
     * the transition proceeds exactly as before, core never knowing the
     * module exists.
     */
    public function testCountBlockingRegistrationRequestsIsZeroWithoutProvider(): void
    {
        $this->assertSame(0, $this->adminService->countBlockingRegistrationRequests());
    }

    public function testActivatePublicYearSucceedsWithoutProvider(): void
    {
        $this->adminService->activatePublicYear(9);

        $this->assertSame('9', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testCountBlockingRegistrationRequestsDelegatesToProvider(): void
    {
        $veto = $this->createMock(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn(3);
        $adminService = new ScoutYearAdminService($this->settingService, $veto);

        $this->assertSame(3, $adminService->countBlockingRegistrationRequests());
    }

    /**
     * The core of the veto (§8.38): refused server-side by the service that
     * actually executes the transition, not merely at display time — so a
     * direct call (a replayed request, bypassing any UI) is refused too.
     */
    public function testActivatePublicYearThrowsWhenProviderReportsBlockingRequests(): void
    {
        $veto = $this->createMock(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn(2);
        $adminService = new ScoutYearAdminService($this->settingService, $veto);

        $this->expectException(ScoutYearTransitionVetoedException::class);

        try {
            $adminService->activatePublicYear(9);
        } finally {
            // The setting must be left untouched — the transition never happened.
            $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        }
    }

    public function testActivatePublicYearSucceedsWhenProviderReportsZero(): void
    {
        $veto = $this->createMock(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn(0);
        $adminService = new ScoutYearAdminService($this->settingService, $veto);

        $adminService->activatePublicYear(9);

        $this->assertSame('9', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testVetoedExceptionCarriesBlockingCount(): void
    {
        $veto = $this->createMock(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn(5);
        $adminService = new ScoutYearAdminService($this->settingService, $veto);

        try {
            $adminService->activatePublicYear(9);
            $this->fail('Expected ScoutYearTransitionVetoedException');
        } catch (ScoutYearTransitionVetoedException $e) {
            $this->assertSame(5, $e->getBlockingRequestCount());
        }
    }
}
