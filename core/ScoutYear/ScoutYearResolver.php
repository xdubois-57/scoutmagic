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
 *   2. Staff year       — setting `staff_scout_year_id`, honored for whoever
 *                         reaches `intendant` RESOLVED IN THAT YEAR.
 *   3. Public current   — setting `current_scout_year_id`, else the date-computed year.
 *
 * This service never touches $_SESSION: the session preview id is passed in by
 * the controller/front-controller layer (see ScoutYearSession).
 *
 * ## Which year each of the two overrides is for
 *
 * **The session preview stays out of authorization, and takes part in
 * nothing but display.** It is an explicit act by a chief — the page it
 * is set from says in so many words that it "ne concerne que votre
 * session" — so it is honoured on the authorization role, and it never
 * contributes to that role. It is accepted for any year that ever
 * existed, which is exactly why: a preview that fed the role would let
 * whoever was Staff d'U in 2019-2020 walk back in. The objection that an
 * admin previewing a year where they are not admin would lock themselves
 * out is answered by that same separation — their role still comes from
 * the public year (ARCHITECTURE.md §4 « Scout year »).
 *
 * **The staff year is a different question, and the role that answers it
 * is the role resolved IN THE STAFF YEAR ITSELF.** This used to be tested
 * against the caller's global role, which produced the two halves of one
 * defect. The animateur of a section in the year that is ending was
 * `chief` by the public year, so they were sent into the year being
 * prepared — where they have no section and no animés. And the animateur
 * recruited for the year being prepared was `identified` by the public
 * year, so the year prepared for them was the one year they could never
 * be shown: to see the staff year you had to already be staff, and you
 * were only staff by the public year.
 *
 * Asking the question in the staff year answers both at once, and gives
 * each person the single year where they really have a section — which is
 * what lets every downstream check keep asking its own question in ONE
 * year, unchanged. `getStaffedSections($effectiveYear)` answers non-empty
 * for both of them without knowing any of this exists.
 *
 * The threshold is `intendant` rather than `chief`, unchanged: a newly
 * appointed intendant has to be able to prepare enrolments and fees
 * before the season starts, exactly like a newly appointed chief.
 */
class ScoutYearResolver
{
    public const SETTING_PUBLIC_YEAR = 'current_scout_year_id';
    public const SETTING_STAFF_YEAR = 'staff_scout_year_id';

    /**
     * Whether the account behind this request reaches `intendant` resolved
     * in a given scout year. Set once by the composition root, which is
     * the only layer that knows who is signed in — this service never
     * touches $_SESSION and holds no email.
     *
     * **Left unset, no staff year is ever served**, and that is the
     * fail-closed direction: the public year is where an ordinary visitor
     * belongs anyway. `public/index.php` is the one place that wires it.
     *
     * @var (callable(int): bool)|null
     */
    private $staffYearEligibility = null;

    /**
     * Memo of the answer per year id. getEffectiveYear() is called once
     * per controller that needs it — a dozen times on some pages — and the
     * callable behind this runs a role resolution, which is several
     * queries.
     *
     * @var array<int, bool>
     */
    private array $staffYearEligibilityMemo = [];

    public function __construct(
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private MemberYearRepository $memberYearRepository
    ) {
    }

    /**
     * @param callable(int): bool $isEligible answers, for a scout year id,
     *        whether the current account reaches `intendant` IN that year
     */
    public function setStaffYearEligibility(callable $isEligible): void
    {
        $this->staffYearEligibility = $isEligible;
        $this->staffYearEligibilityMemo = [];
    }

    /**
     * Resolve the effective scout year for the current request.
     */
    public function getEffectiveYear(?int $sessionOverrideId, Role $role): EffectiveScoutYear
    {
        // 1. Session preview — chief/admin only. Display, never authorization.
        if ($sessionOverrideId !== null && $role->hasAccess(Role::CHIEF)) {
            $year = $this->scoutYearService->findById($sessionOverrideId);
            if ($year !== null) {
                return new EffectiveScoutYear($year['id'], $year['label'], 'session');
            }
        }

        // 2. Staff year — whoever reaches `intendant` in that year itself.
        $staffId = $this->getStaffYearId();
        if ($staffId !== null && $this->isEligibleForStaffYear($staffId)) {
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
     * The year an in-request question about **who the caller is** must be
     * asked in — « is this person a chef d'unité », « may they manage this
     * asset » — as opposed to a question about what to display.
     *
     * It is the year this person is served, minus the session preview,
     * and both halves matter.
     *
     * **Minus the preview**, because a preview is chosen by the very
     * person it would authorise and is accepted for any year that ever
     * existed: whoever was Staff d'U in 2019-2020 could preview that year
     * and get every screen gated on `isUnitChief()` back. It decides what
     * is displayed, never who may see it.
     *
     * **The served year and not the date-computed one**, because the two
     * part company every 1 September until somebody runs the transition —
     * and on that morning the date-computed year holds no roster at all,
     * so a question asked in it locks the real chef d'unité out of their
     * own site. It is also the only year that answers both animateurs of
     * a transition correctly, each in the year where they have a section.
     *
     * No role parameter, deliberately: the preview is the only branch of
     * getEffectiveYear() that ever read one, and this method does not
     * take that branch.
     */
    public function getAuthorizationYear(): EffectiveScoutYear
    {
        return $this->getEffectiveYear(null, Role::PUBLIC);
    }

    /**
     * Whether the account behind this request may be served the staff
     * year, asked in that year rather than about the caller in general.
     *
     * Unwired — a background root, a narrow test — the answer is no, and
     * the public year is served instead.
     */
    private function isEligibleForStaffYear(int $staffYearId): bool
    {
        if ($this->staffYearEligibility === null) {
            return false;
        }

        return $this->staffYearEligibilityMemo[$staffYearId]
            ??= ($this->staffYearEligibility)($staffYearId);
    }

    /**
     * Get the public current year: the `current_scout_year_id` setting when set
     * and resolvable, otherwise the date-computed year (auto-created).
     *
     * This is the year the site is ON: what a member or a visitor is
     * served, what a Desk import writes into, and the year an access
     * decision is anchored on (`Core\ScoutYear\AuthorizationYearService`
     * bounds the other candidates against it). It never reflects a
     * preview, and never a staff override.
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
