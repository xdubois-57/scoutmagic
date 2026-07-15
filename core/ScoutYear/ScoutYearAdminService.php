<?php

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\ScoutYearService;
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
     * Enforce the automatic public-year switch that happens outside the manual
     * transition window (from September 30, "whatever happens").
     *
     * If the configured public year is set but stale — different from the
     * date-based scout year — it is advanced to the date-based year and the
     * staff year is cleared. Does nothing during the manual window (August–
     * September 29) or when no public year is configured (the date fallback
     * already yields the correct year).
     *
     * @return string|null The new year label if a switch occurred, else null.
     */
    public function enforceAutomaticSwitch(
        ScoutYearService $scoutYearService,
        ?int $currentPublicYearId,
        \DateTimeInterface $now
    ): ?string {
        if (ScoutYearService::isSwitchWindow($now)) {
            return null;
        }
        if ($currentPublicYearId === null) {
            return null;
        }

        $dateLabel = ScoutYearService::labelForDate($now);
        $dateYearId = $scoutYearService->ensureYear($dateLabel);
        if ($currentPublicYearId === $dateYearId) {
            return null;
        }

        $this->activatePublicYear($dateYearId);

        return $dateLabel;
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
