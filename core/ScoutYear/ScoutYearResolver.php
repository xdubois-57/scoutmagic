<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * The scout years an ACCESS decision may consider — the effective year,
     * plus the date-computed one while the two are one year apart.
     *
     * WHY TWO YEARS. A scout year turns over on the 1st of September; the
     * roster of the new one arrives with the Desk import, which is days or
     * weeks later, and the site's own transition workflow (Core\ScoutYear\
     * ScoutYearTransitionService) deliberately holds the public year back
     * until its last step. So there is a window — every year, on every
     * installation — in which "which year is this?" has two defensible
     * answers, and a membership written in one of them is invisible from
     * the other. Asking either question alone locks somebody out of pages
     * they held yesterday and will hold tomorrow: on the date-computed
     * year, nobody is Staff d'U until the import lands; on the effective
     * year, nobody is yet whatever the new roster makes them.
     *
     * So an access decision takes the HIGHEST answer the two years give,
     * rather than one year's answer. Deliberately a widening: somebody who
     * was a unit chief in one of the two years keeps the pages that gate on
     * it for as long as both years are in play. That is the point — the
     * alternative is a chief locked out of their own configuration in the
     * fortnight the site is most in use.
     *
     * ADJACENT ONLY, and that bound is what keeps this from being
     * permanent. The two years disagree from the 1st of September until
     * somebody runs the workflow's last step, and nothing forces them to:
     * on an installation where the public year was pinned and forgotten,
     * an unbounded rule would grant a long-departed chief their access for
     * ever. One year apart is every real transition and no stale pin.
     *
     * SYMMETRIC, because the transition runs in both directions. Step 9
     * (« Activer l'année cible pour les staffs ») can put the effective
     * year AHEAD of the calendar in August; the 1st of September puts the
     * calendar ahead of the public year. Both are the same fortnight seen
     * from two sides.
     *
     * Read-only on purpose: findByLabel() rather than getCurrentYear(),
     * which calls ensureYear() and would CREATE next year's row as a side
     * effect of asking an authorization question. A year that does not
     * exist yet holds no memberships, so there is nothing to union with.
     *
     * @return list<int> The effective year first, then the date-computed
     *                   one when it qualifies. Never empty, never repeats.
     */
    public function getAccessYearIds(?int $sessionOverrideId, Role $role): array
    {
        $effective = $this->getEffectiveYear($sessionOverrideId, $role);
        $ids = [$effective->id];

        $dateLabel = ScoutYearService::labelForDate(new \DateTimeImmutable());
        $dateYear = $this->scoutYearService->findByLabel($dateLabel);
        if ($dateYear === null || (int) $dateYear['id'] === $effective->id) {
            return $ids;
        }

        if (self::areAdjacent($effective->label, (string) $dateYear['label'])) {
            $ids[] = (int) $dateYear['id'];
        }

        return $ids;
    }

    /**
     * Whether two `YYYY-YYYY` labels name consecutive scout years, in
     * either order.
     *
     * The label is the comparison rather than start_date because the label
     * is what ScoutYearService::ensureYear() derives every other column
     * from — a row whose dates were edited by hand still sorts by the year
     * it is called.
     */
    private static function areAdjacent(string $one, string $other): bool
    {
        $startOfOne = (int) explode('-', $one)[0];
        $startOfOther = (int) explode('-', $other)[0];

        return $startOfOne !== 0
            && $startOfOther !== 0
            && abs($startOfOne - $startOfOther) === 1;
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
