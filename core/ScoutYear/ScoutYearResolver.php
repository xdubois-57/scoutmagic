<?php

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Security\Role;

/**
 * Resolves the scout year in effect for a request, by priority:
 *
 *   1. Session preview  — honored only for role >= chief.
 *   2. Staff year       — setting `staff_scout_year_id`, honored for role >= intendant.
 *   3. Public current   — setting `current_scout_year_id`, else the date-computed year.
 *
 * This service never touches $_SESSION: the session preview id is passed in by
 * the controller/front-controller layer (see ScoutYearSession).
 */
class ScoutYearResolver
{
    public const SETTING_PUBLIC_YEAR = 'current_scout_year_id';
    public const SETTING_STAFF_YEAR = 'staff_scout_year_id';

    public function __construct(
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private MemberYearRepository $memberYearRepository
    ) {
    }

    /**
     * Resolve the effective scout year for the current request.
     */
    public function getEffectiveYear(?int $sessionOverrideId, Role $role): EffectiveScoutYear
    {
        // 1. Session preview — chief/admin only.
        if ($sessionOverrideId !== null && $role->hasAccess(Role::CHIEF)) {
            $year = $this->scoutYearService->findById($sessionOverrideId);
            if ($year !== null) {
                return new EffectiveScoutYear($year['id'], $year['label'], 'session');
            }
        }

        // 2. Staff year — intendant and above.
        $staffId = $this->getStaffYearId();
        if ($staffId !== null && $role->hasAccess(Role::INTENDANT)) {
            $year = $this->scoutYearService->findById($staffId);
            if ($year !== null) {
                return new EffectiveScoutYear($year['id'], $year['label'], 'staff');
            }
        }

        // 3. Public current year (setting, else date fallback).
        $public = $this->getCurrentPublicYear();

        return new EffectiveScoutYear($public['id'], $public['label'], null);
    }

    /**
     * Get the public current year: the `current_scout_year_id` setting when set
     * and resolvable, otherwise the date-computed year (auto-created).
     *
     * This is the year used for login role resolution and Desk import — it never
     * reflects a preview or staff override.
     *
     * @return array{id: int, label: string, start_date: string, end_date: string}
     */
    public function getCurrentPublicYear(): array
    {
        $publicId = $this->getPublicYearId();
        if ($publicId !== null) {
            $year = $this->scoutYearService->findById($publicId);
            if ($year !== null) {
                return $year;
            }
        }

        return $this->scoutYearService->getCurrentYear();
    }

    /**
     * The configured public year id, or null when unset (0) / not configured.
     */
    public function getPublicYearId(): ?int
    {
        return $this->readYearSetting(self::SETTING_PUBLIC_YEAR);
    }

    /**
     * The configured staff year id, or null when unset (0).
     */
    public function getStaffYearId(): ?int
    {
        return $this->readYearSetting(self::SETTING_STAFF_YEAR);
    }

    /**
     * All known scout years, newest first. Ensures the year following the public
     * current year always exists, so the next year can be previewed and imported
     * before it has been configured.
     *
     * @return array<int, array{id: int, label: string, start_date: string, end_date: string}>
     */
    public function listYears(): array
    {
        $current = $this->getCurrentPublicYear();
        $this->scoutYearService->ensureYear(ScoutYearService::nextLabel($current['label']));

        return $this->scoutYearService->getAll();
    }

    public function countMembers(int $scoutYearId): int
    {
        return $this->memberYearRepository->countActiveByYear($scoutYearId);
    }

    public function countSections(int $scoutYearId): int
    {
        return $this->memberYearRepository->countConfiguredSectionsForYear($scoutYearId);
    }

    private function readYearSetting(string $key): ?int
    {
        $value = (int) $this->settingService->get($key, null, '0');

        return $value > 0 ? $value : null;
    }
}
