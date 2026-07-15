<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ScoutYearAdminServiceTest extends TestCase
{
    private SettingService $settingService;
    private ScoutYearAdminService $adminService;
    private ScoutYearService $scoutYearService;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->scoutYearService = new ScoutYearService($pdo);
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

    public function testEnforceAutomaticSwitchAdvancesStalePublicYearOutsideWindow(): void
    {
        $oldId = $this->scoutYearService->ensureYear('2024-2025');
        $this->adminService->activatePublicYear($oldId);
        $this->adminService->activateStaffYear($this->scoutYearService->ensureYear('2025-2026'));

        // October → outside the window; public year is stale (2024-2025 vs 2025-2026).
        $label = $this->adminService->enforceAutomaticSwitch(
            $this->scoutYearService,
            $oldId,
            new \DateTimeImmutable('2025-10-05')
        );

        $this->assertSame('2025-2026', $label);
        $newId = $this->scoutYearService->ensureYear('2025-2026');
        $this->assertSame((string) $newId, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testEnforceAutomaticSwitchDoesNothingDuringWindow(): void
    {
        $oldId = $this->scoutYearService->ensureYear('2024-2025');
        $this->adminService->activatePublicYear($oldId);

        // September 15 → inside the manual window: no automatic switch.
        $label = $this->adminService->enforceAutomaticSwitch(
            $this->scoutYearService,
            $oldId,
            new \DateTimeImmutable('2025-09-15')
        );

        $this->assertNull($label);
        $this->assertSame((string) $oldId, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testEnforceAutomaticSwitchDoesNothingWhenAlreadyCurrent(): void
    {
        $currentId = $this->scoutYearService->ensureYear('2025-2026');
        $this->adminService->activatePublicYear($currentId);

        $label = $this->adminService->enforceAutomaticSwitch(
            $this->scoutYearService,
            $currentId,
            new \DateTimeImmutable('2025-10-05')
        );

        $this->assertNull($label);
    }
}
