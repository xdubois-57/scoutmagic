<?php

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\SettingService;

/**
 * Write operations for the scout-year state (public current year and staff year).
 *
 * Values are stored as core settings and written via SettingService::setInternal
 * (they are not hand-editable through the settings UI).
 */
class ScoutYearAdminService
{
    public function __construct(private SettingService $settingService)
    {
    }

    /**
     * Activate a staff year: chiefs/intendants see this year, the public keeps
     * the current public year.
     */
    public function activateStaffYear(int $yearId): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $yearId);
    }

    /**
     * Clear the staff year.
     */
    public function deactivateStaffYear(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, '0');
    }

    /**
     * Activate a year for everyone. This transitions the whole site: it sets the
     * public current year and clears any staff year (the staff and public are now
     * aligned on the same year).
     */
    public function activatePublicYear(int $yearId): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $yearId);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, '0');
    }
}
