<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;

/**
 * Builds the set of scout years an access decision may be taken in.
 *
 * During the annual transition two people need opposite answers on the
 * same day. The animateur of a section in the year that is ending is
 * still staff, and must keep the year they actually have a section in;
 * the animateur who only exists in the year being prepared is staff
 * too, and must be let into that one. Resolving the role against the
 * public year alone answers only the first, and resolving it against
 * the staff year alone only the second — hence a set rather than a
 * year (ARCHITECTURE.md §4 « Scout year »).
 *
 * Three sources feed it, and only three:
 *
 * 1. the **public year** — `current_scout_year_id`, the state of the site;
 * 2. the **date-computed year** — what the calendar says the season is,
 *    which runs ahead of the public year between 1 September and the day
 *    a chef d'unité actually runs the transition;
 * 3. the **staff year** — `staff_scout_year_id`, an administrative
 *    decision to open the next year to the staff early.
 *
 * All three are properties of the installation. **The session preview is
 * not one of them, and is not a parameter of this service**: it is
 * chosen by the very person it would authorise, accepted for any year
 * that ever existed, so an authorization set built from it hands the
 * site back to whoever was Staff d'U in 2019-2020. It decides what is
 * displayed, never who may see it.
 *
 * Two invariants the tests pin, both of which cost something the day
 * they are broken:
 *
 * - **One year of slack, in both directions.** Without a bound the staff
 *   year and the public year drift apart for as long as nobody runs the
 *   transition, and nothing on the site forces anybody to run it — on an
 *   instance left alone, a chief who left two seasons ago would keep
 *   their access indefinitely.
 * - **Nothing is ever created.** ScoutYearService::getCurrentYear() calls
 *   ensureYear(), which INSERTs on 1 September. An authorization question
 *   answered on every request must not write a row, so this service uses
 *   findByLabel()/findById() only and lives with the answer « that year
 *   does not exist here ».
 *
 * Usable without a session: the scheduled and mail-driven callers of
 * `Modules\Rental` resolve their year through this exact service.
 */
class AuthorizationYearService
{
    public function __construct(
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        /**
         * Injected only by the tests that pin the 1 September boundary
         * and the one-year bound; production always reads the real clock.
         */
        private readonly ?\DateTimeImmutable $now = null
    ) {
    }

    public function resolve(): AuthorizationYears
    {
        $publicYear = $this->findConfigured(ScoutYearResolver::SETTING_PUBLIC_YEAR);

        // The date-computed year, read and never created (see the class
        // docblock). ScoutYearService::labelForDate() is the same
        // arithmetic getCurrentYear() applies — a scout year turns over
        // on 1 September — minus the INSERT that follows it there.
        $dateLabel = ScoutYearService::labelForDate($this->now ?? new \DateTimeImmutable());
        $dateYear = $this->scoutYearService->findByLabel($dateLabel);

        // Mirrors ScoutYearResolver::getCurrentPublicYear()'s own
        // fallback: with `current_scout_year_id` unset, the public year
        // IS the date-computed one. Keeping that here is what makes the
        // threshold in Core\Security\RoleResolver mean the same thing on
        // a freshly installed site as on a configured one.
        $publicYear ??= $dateYear;

        $anchorLabel = $publicYear['label'] ?? $dateLabel;

        $staffYear = $this->findConfigured(ScoutYearResolver::SETTING_STAFF_YEAR);

        $ids = [];
        foreach ([$publicYear, $dateYear, $staffYear] as $candidate) {
            if ($candidate === null || !self::isWithinOneYearOf($candidate['label'], $anchorLabel)) {
                continue;
            }
            $ids[$candidate['id']] = $candidate['id'];
        }

        return new AuthorizationYears($publicYear['id'] ?? null, array_values($ids));
    }

    /**
     * A year id read from a setting and resolved to a real row — null
     * when the setting is unset (stored as `0`) or names a year that no
     * longer exists.
     *
     * @return array{id: int, label: string, start_date: string, end_date: string}|null
     */
    private function findConfigured(string $settingKey): ?array
    {
        $id = (int) $this->settingService->get($settingKey, null, '0');

        return $id > 0 ? $this->scoutYearService->findById($id) : null;
    }

    /**
     * Scout year labels are `YYYY-YYYY` and their first half orders them,
     * so « one year apart » is a subtraction rather than a date range.
     */
    private static function isWithinOneYearOf(string $label, string $anchorLabel): bool
    {
        return abs(self::startYear($label) - self::startYear($anchorLabel)) <= 1;
    }

    private static function startYear(string $label): int
    {
        return (int) explode('-', $label)[0];
    }
}
