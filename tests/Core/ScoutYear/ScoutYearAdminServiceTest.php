<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

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
}
