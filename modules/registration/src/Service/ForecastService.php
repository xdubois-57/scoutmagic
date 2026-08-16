<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;

/**
 * "Prévisions" page's domain logic (module spec, iteration 7): projects the
 * unit's headcount for the target scout year (public year + 1, same
 * deliberate ScoutYearResolver::getEffectiveYear() exception as Passage and
 * the mailing list — see Controller\PassageController's own docblock) by
 * combining four sources without double counting:
 *
 *   (a) CERTAIN — animés already re-imported from Desk for the target year
 *       (Service\PassageService::getAnimeMemberYears($targetYearId)). Real
 *       data; only ever non-empty once an import for that year has
 *       happened. Naturally excludes anyone marked leaving on their own
 *       target-year row and anyone not re-imported at all (e.g. a real
 *       departure) — both "apport 1" and "moins apport 2" fall out of the
 *       same query for free.
 *   (b) HYPOTHESIS — current-year animés who are neither marked leaving nor
 *       at the last rank of their branch (i.e. not a Service\PassageService::
 *       getBranchChanges() candidate), projected one scout year forward via
 *       MemberYearService::getEffectiveAge() with the TARGET year's
 *       reference year — never a hand-rolled "+1" on year_in_branch. Stays
 *       in the same section.
 *   (c) HYPOTHESIS — animés changing branch (Service\PassageService::
 *       getBranchChanges()), placed in their chosen destination section, or
 *       "non attribués" (origin: passage) when none is chosen yet.
 *   (d) HYPOTHESIS — accepted-but-not-yet-encoded registration requests for
 *       the target year (Service\PassageService::getNewRegistrations()),
 *       placed in their "section prévue" (Modules\Registration\Repository\
 *       RegistrationRequest::$intendedSectionId — the exact same field the
 *       request's own fiche reads and writes, module spec), or "non
 *       attribués" (origin: inscription) when none is chosen yet.
 *
 * The double-counting risk the module spec calls out explicitly — a request
 * encoded in Desk and reimported existing both as a member and as a request
 * — cannot occur here: getNewRegistrations() only ever returns 'accepted'
 * requests (Repository\RegistrationRequestRepository::findAcceptedForYear()),
 * and a request becomes 'encoded' (Service\RequestStatusService/
 * MigrationService) at the exact moment it gets a real linked_member_id —
 * the two statuses are mutually exclusive by construction, so bucket (d)
 * structurally never contains anyone bucket (a) could also contain.
 *
 * (b) and (c) get a second, defensive dedup on top: a member_id already
 * present in the target year's real member_years rows (bucket (a)) is
 * excluded from both, so a *partial* Desk import for the target year — some
 * children re-imported, others still only planned — never double-counts
 * the ones that already made it in with whatever section Desk now shows for
 * them (which may since have been corrected there directly, overriding an
 * earlier passage/destination decision).
 *
 * Certain vs. hypothesis is tracked per section and unit-wide throughout —
 * only bucket (a) ever counts as "certain" (module spec: "un passage non
 * validé ou une inscription non encodée est une hypothèse" — a *chosen*
 * destination is still just staff's plan, not yet a Desk fact).
 *
 * Two invariants this class is responsible for, both regression-tested:
 *
 *   1. `sum(sections[].total) + unassigned.total === summary.projected_total`.
 *      buildViewModel() is the ONLY place that decides where a row lands,
 *      so a row can never be counted twice or fall between two counters.
 *   2. Everyone in `current_total` who is not in the projection is visible
 *      in exactly one of `departures_count` (marked leaving) or
 *      `aging_out_count` (their projected effective age leaves the four
 *      animés branches — last-year Pionniers, mostly). The variation is
 *      then genuinely explained by the figures shown next to it.
 *
 * Every age question — including for members a chief has advanced or held
 * back a year (`member_years.scout_year_offset`) — goes through
 * MemberYearService::getEffectiveAge() with the relevant year's reference
 * year. Nothing in here adds or subtracts a year by hand.
 */
class ForecastService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private SectionService $sectionService,
        private PassageService $passageService,
        private MemberYearService $memberYearService = new MemberYearService()
    ) {
    }

    /**
     * @return array{
     *   summary: array{projected_total: int, current_total: int, variation: int, departures_count: int, aging_out_count: int, new_registrations_count: int},
     *   sections: array<int, array{id: int, label: string, color: string, total_years_in_branch: int, year_segments: array<int, int>, gender: array{male: int, female: int, other: int, total: int}, total: int, certain_total: int, hypothesis_total: int}>,
     *   unassigned: array{total: int, from_passage: int, from_registration: int, from_unknown_section: int},
     *   pyramid: array<int, array{male: int, female: int, other: int}>,
     *   pyramid_max: int
     * }
     */
    public function getForecast(
        int $currentYearId,
        string $currentYearLabel,
        int $targetYearId,
        string $targetYearLabel,
        string $referenceMonthDay
    ): array {
        // includeHidden: TRUE deliberately, and it must stay that way. The
        // Passage page offers destinations (and the fiche offers a "section
        // prévue") from getAllWithBranches(TRUE) — a hidden-but-active
        // section is a perfectly ordinary, assignable destination. Building
        // this map from the visible-only list instead made every such
        // assignment fall through buildViewModel()'s unknown-section guard
        // and disappear from the page entirely: counted in the unit total
        // and the pyramid, shown in no section, and not "non attribué"
        // either. buildViewModel() drops hidden sections that ended up with
        // nobody, so this never adds empty clutter.
        $sections = $this->sectionService->getAllWithBranches(true);

        /** @var array<int, array{section_id: ?int, year_in_branch: ?int, gender: ?string, birth_year: ?int, certain: bool, origin: string}> $projected */
        $projected = [];
        $agingOut = 0;

        // (a) Certain — real target-year Desk data.
        $targetMemberIds = [];
        foreach ($this->passageService->getAnimeMemberYears($targetYearId) as $row) {
            $memberId = (int) $row['member_id'];
            $targetMemberIds[$memberId] = true;

            $birthYear = MemberYearService::extractBirthYear(
                $row['birth_date_encrypted'] !== null ? $this->encryption->decrypt($row['birth_date_encrypted']) : null
            );
            $effectiveAge = $this->memberYearService->getEffectiveAge(
                $birthYear,
                (int) $row['scout_year_offset'],
                MemberYearService::referenceYearFromScoutYearLabel($targetYearLabel)
            );
            if (!$effectiveAge->isInKnownBranch()) {
                continue;
            }

            $projected[] = [
                'section_id' => $row['section_id'] !== null ? (int) $row['section_id'] : null,
                'year_in_branch' => $effectiveAge->yearInBranch,
                'gender' => $this->classifyGender(
                    $row['gender_encrypted'] !== null ? $this->encryption->decrypt($row['gender_encrypted']) : null
                ),
                'birth_year' => $birthYear,
                'certain' => true,
                'origin' => 'desk',
            ];
        }

        // (b) Hypothesis — continuing current-year animés, projected one
        // year forward. Excludes anyone already real in the target year and
        // anyone at the last rank of their branch (those are (c)'s domain).
        $currentReferenceYear = MemberYearService::referenceYearFromScoutYearLabel($currentYearLabel);
        $targetReferenceYear = MemberYearService::referenceYearFromScoutYearLabel($targetYearLabel);
        $currentAnimes = $this->passageService->getAnimeMemberYears($currentYearId);

        foreach ($currentAnimes as $row) {
            $memberId = (int) $row['member_id'];
            if (isset($targetMemberIds[$memberId])) {
                continue;
            }

            $birthYear = MemberYearService::extractBirthYear(
                $row['birth_date_encrypted'] !== null ? $this->encryption->decrypt($row['birth_date_encrypted']) : null
            );
            $currentAge = $this->memberYearService->getEffectiveAge($birthYear, (int) $row['scout_year_offset'], $currentReferenceYear);
            $projectedAge = $this->memberYearService->getEffectiveAge($birthYear, (int) $row['scout_year_offset'], $targetReferenceYear);

            // The decisive question is where they land NEXT year, never
            // where they sit today — a member held back by scout_year_offset
            // can be below Baladins' entry age right now and still reach 1st
            // year next year, and gating on the current age (as this once
            // did) dropped exactly those from the projection. Anyone whose
            // PROJECTED age leaves the four animés branches is counted as
            // "aging out" instead: last-year Pionniers, mostly, who would
            // otherwise shrink the unit total with nothing on the page to
            // explain the drop.
            if (!$projectedAge->isInKnownBranch()) {
                $agingOut++;
                continue;
            }
            if ($currentAge->isInKnownBranch() && $currentAge->yearInBranch === $currentAge->totalYearsInBranch) {
                continue; // last rank — a Service\PassageService::getBranchChanges() candidate instead, see (c)
            }

            $projected[] = [
                'section_id' => $row['section_id'] !== null ? (int) $row['section_id'] : null,
                'year_in_branch' => $projectedAge->yearInBranch,
                'gender' => $this->classifyGender(
                    $row['gender_encrypted'] !== null ? $this->encryption->decrypt($row['gender_encrypted']) : null
                ),
                'birth_year' => $birthYear,
                'certain' => false,
                'origin' => 'continuing',
            ];
        }

        // (c) Hypothesis — branch changes, placed in their destination (or
        // "non attribués" when none is chosen).
        $branchChanges = $this->passageService->getBranchChanges($currentYearId, $currentYearLabel, $targetYearId);
        foreach ($branchChanges as $group) {
            foreach ($group['members'] as $member) {
                if (isset($targetMemberIds[(int) $member['member_id']])) {
                    continue;
                }

                $destinationSectionId = $member['destination_section_id'];

                $projected[] = [
                    // Still counted in the unit total and the pyramid when
                    // unassigned (null here) — module spec: "la pyramide et
                    // les totaux doivent inclure les non attribués". Only
                    // the per-section breakdown skips them (buildViewModel),
                    // which is also where the "non attribué" tallying now
                    // happens, so every row is accounted for exactly once.
                    'section_id' => $destinationSectionId !== null ? (int) $destinationSectionId : null,
                    // year_in_branch/gender/birth_year all resolved below in
                    // one pass, keyed by member_id — the rank is derived
                    // from the member's own projected effective age (which
                    // honours scout_year_offset), never hardcoded to 1.
                    'year_in_branch' => null,
                    'gender' => null,
                    'birth_year' => null,
                    'certain' => false,
                    'origin' => 'passage',
                    'member_id' => (int) $member['member_id'],
                ];
            }
        }

        // (d) Hypothesis — accepted new registrations, placed in their
        // section prévue (or "non attribués" when none is chosen). Never
        // 'encoded' requests (see class docblock) — the double-counting
        // guard is structural, not a runtime check.
        $newRegistrations = $this->passageService->getNewRegistrations($targetYearId, $targetYearLabel, $referenceMonthDay, $currentYearId);
        foreach ($newRegistrations as $row) {
            $request = $row['request'];

            $projected[] = [
                // Still counted in the unit total and the pyramid when
                // unassigned (null here) — see the matching (c) comment above.
                'section_id' => $request->intendedSectionId,
                'year_in_branch' => $row['slot']['year_in_branch'] ?? null,
                'gender' => $this->classifyGender($request->gender),
                'birth_year' => $request->birthYear(),
                'certain' => false,
                'origin' => 'registration',
            ];
        }

        // (c)'s rows deferred their gender/birth year/rank lookup (they share
        // the exact address/name resolution PassageService already did — no
        // point decrypting the same row twice); resolve them now in one pass.
        $projected = $this->resolveDeferredMemberData($projected, $currentYearId, $targetReferenceYear);

        return $this->buildViewModel(
            $projected,
            $sections,
            $this->countCurrentAnimes($currentYearId),
            $this->countDeparturesForYear($currentYearId),
            $agingOut,
            count($newRegistrations)
        );
    }

    /**
     * Reshapes Service\SlotService::capacityBreakdownForYear()'s flat,
     * per-slot row list (already the source of the admin config page's
     * "Vérification des capacités" table — same numbers, never a second
     * computation) into the branch-grouped, colored shape the Prévisions
     * page's remaining-capacity bars need, in the same visual language as
     * Modules\MemberStats\Service\MemberStatsService::getStatistics()'s
     * own branch/rows grouping (module_stats.color resolution duplicated
     * here in miniature rather than shared, since that method is private
     * and this module has its own dependencies for it already).
     *
     * @param array<int, array{age_branch_id: int, branch_label: string, branch_sort_order: int, year_in_branch: int, capacity: int, projected: int, accepted: int, remaining: int, tier: string}> $capacityBreakdown
     * @return array<int, array{label: string, color: string, rows: array<int, array{year_in_branch: int, capacity: int, remaining: int, tier: string}>}>
     */
    public function groupCapacityByBranch(array $capacityBreakdown): array
    {
        // includeHidden, same as getForecast(): this only picks a colour,
        // but a branch whose sections are all hidden would otherwise lose
        // its own colour and fall back to the federation default.
        $allSections = $this->sectionService->getAllWithBranches(true);

        $byBranch = [];
        foreach ($capacityBreakdown as $row) {
            $branchId = $row['age_branch_id'];
            if (!isset($byBranch[$branchId])) {
                $byBranch[$branchId] = [
                    'label' => $row['branch_label'],
                    'color' => $this->resolveBranchColorBySortOrder($row['branch_sort_order'], $allSections),
                    'rows' => [],
                ];
            }

            $byBranch[$branchId]['rows'][] = [
                'year_in_branch' => $row['year_in_branch'],
                'capacity' => $row['capacity'],
                'remaining' => $row['remaining'],
                'tier' => $row['tier'],
            ];
        }

        return array_values($byBranch);
    }

    /**
     * Same rule Modules\MemberStats\Service\MemberStatsService::
     * resolveBranchColor() uses: the color of this branch's own
     * representative section (first by desk_code, when a branch has
     * several — e.g. two Louveteaux packs), falling back to the plain
     * federation default when the branch has no section at all.
     *
     * @param array<int, array{id: int, desk_code: string, branch_sort_order: int, color: ?string}> $allSections
     */
    private function resolveBranchColorBySortOrder(int $sortOrder, array $allSections): string
    {
        $branchSections = array_values(array_filter($allSections, static fn(array $s) => $s['branch_sort_order'] === $sortOrder));
        if ($branchSections === []) {
            return MemberYearService::colorForBranchSortOrder($sortOrder);
        }

        usort($branchSections, static fn(array $a, array $b) => $a['desk_code'] <=> $b['desk_code']);

        return SectionService::colorForSection($branchSections[0]);
    }

    /**
     * How many animés the unit actually has RIGHT NOW — active, non-staff,
     * sectioned, and deliberately counting members marked leaving as well
     * as those whose effective age sits outside the four animés branches.
     *
     * This is the "before" side of the Variation card, so it has to be the
     * real present-day headcount, not a filtered one. It previously reused
     * Service\PassageService::getAnimeMemberYears() (which carries
     * `leaving = 0`) and then dropped out-of-branch members on top, so a
     * unit losing someone to an announced departure showed "Variation: 0" —
     * the departure had already been subtracted from both sides of the
     * subtraction and cancelled itself out. Everyone counted here who is
     * gone next year now shows up in exactly one of departures_count or
     * aging_out_count.
     */
    private function countCurrentAnimes(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT my.id)
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND f.role NOT IN ('chief', 'admin', 'intendant') AND mf.section_id IS NOT NULL"
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * (c)'s branch-change rows only carry a member_id — this resolves their
     * gender, birth year AND year-in-branch in one extra pass rather than
     * re-querying per row.
     *
     * The rank is computed through MemberYearService::getEffectiveAge()
     * against the TARGET year's reference year, honouring the member's own
     * scout_year_offset. It used to be hardcoded to 1 ("always the bottom
     * rank of the arrival branch"), which happens to be right whenever the
     * branches are contiguous but is precisely the kind of hand-rolled age
     * arithmetic this module forbids everywhere else — and it silently
     * assumed an offset of 0.
     *
     * @param array<int, array<string, mixed>> $projected
     * @return array<int, array<string, mixed>>
     */
    private function resolveDeferredMemberData(array $projected, int $currentYearId, int $targetReferenceYear): array
    {
        $memberIds = [];
        foreach ($projected as $row) {
            if (array_key_exists('member_id', $row)) {
                $memberIds[] = $row['member_id'];
            }
        }
        if ($memberIds === []) {
            return $projected;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, gender_encrypted, birth_date_encrypted, scout_year_offset FROM member_years
             WHERE scout_year_id = ? AND member_id IN ({$placeholders})"
        );
        $stmt->execute([$currentYearId, ...$memberIds]);

        $byMemberId = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byMemberId[(int) $row['member_id']] = $row;
        }

        foreach ($projected as &$row) {
            if (!array_key_exists('member_id', $row)) {
                continue;
            }
            $source = $byMemberId[$row['member_id']] ?? null;
            $row['gender'] = $this->classifyGender(
                $source !== null && $source['gender_encrypted'] !== null ? $this->encryption->decrypt($source['gender_encrypted']) : null
            );
            $birthYear = $source !== null
                ? MemberYearService::extractBirthYear($source['birth_date_encrypted'] !== null ? $this->encryption->decrypt($source['birth_date_encrypted']) : null)
                : null;
            $row['birth_year'] = $birthYear;
            $row['year_in_branch'] = $source !== null
                ? $this->memberYearService
                    ->getEffectiveAge($birthYear, (int) $source['scout_year_offset'], $targetReferenceYear)
                    ->yearInBranch
                : null;
            unset($row['member_id']);
        }
        unset($row);

        return $projected;
    }

    /**
     * @param array<int, array{section_id: ?int, year_in_branch: ?int, gender: ?string, birth_year: ?int, certain: bool, origin: string}> $projected
     * @param array<int, array{id: int, desk_code: string, name: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int, is_visible: bool, color: ?string}> $sections
     * @return array{
     *   summary: array{projected_total: int, current_total: int, variation: int, departures_count: int, aging_out_count: int, new_registrations_count: int},
     *   sections: array<int, array{id: int, label: string, color: string, total_years_in_branch: int, year_segments: array<int, int>, gender: array{male: int, female: int, other: int, total: int}, total: int, certain_total: int, hypothesis_total: int}>,
     *   unassigned: array{total: int, from_passage: int, from_registration: int, from_unknown_section: int},
     *   pyramid: array<int, array{male: int, female: int, other: int}>,
     *   pyramid_max: int
     * }
     */
    private function buildViewModel(
        array $projected,
        array $sections,
        int $currentTotal,
        int $departuresCount,
        int $agingOutCount,
        int $newRegistrationsCount
    ): array {
        $sectionsById = [];
        foreach ($sections as $section) {
            $sectionsById[$section['id']] = [
                'id' => $section['id'],
                'label' => $section['name'] ?? $section['desk_code'],
                'color' => SectionService::colorForSection($section),
                'total_years_in_branch' => $this->totalYearsForBranchSortOrder((int) $section['branch_sort_order']),
                'year_segments' => [],
                'gender' => ['male' => 0, 'female' => 0, 'other' => 0, 'total' => 0],
                'total' => 0,
                'certain_total' => 0,
                'hypothesis_total' => 0,
                'is_visible' => (bool) $section['is_visible'],
            ];
        }

        $pyramid = [];
        $pyramidMax = 0;
        $unassignedFromPassage = 0;
        $unassignedFromRegistration = 0;
        $unassignedFromUnknownSection = 0;

        foreach ($projected as $row) {
            if ($row['birth_year'] !== null) {
                $pyramid[$row['birth_year']] ??= ['male' => 0, 'female' => 0, 'other' => 0];
                $pyramid[$row['birth_year']][$row['gender']]++;
                $pyramidMax = max($pyramidMax, $pyramid[$row['birth_year']]['male'], $pyramid[$row['birth_year']]['female']);
            }

            // Every row that cannot land in a real section row is tallied
            // here, so `sum(sections) + unassigned === projected_total`
            // holds by construction rather than by two counters happening
            // to agree. A section id we don't recognise (deleted, or gone
            // inactive since the pick was made) is its own bucket: it is
            // NOT a "decision still to make" like the two below, and
            // silently dropping it — which is what the old guard did,
            // commented "shouldn't happen" — made people vanish from the
            // page while still inflating the unit total.
            $sectionId = $row['section_id'];
            if ($sectionId === null || !isset($sectionsById[$sectionId])) {
                if ($sectionId !== null) {
                    $unassignedFromUnknownSection++;
                } elseif ($row['origin'] === 'registration') {
                    $unassignedFromRegistration++;
                } else {
                    $unassignedFromPassage++;
                }
                continue;
            }

            $sectionsById[$sectionId]['total']++;
            $sectionsById[$sectionId]['gender'][$row['gender']]++;
            $sectionsById[$sectionId]['gender']['total']++;
            $row['certain'] ? $sectionsById[$sectionId]['certain_total']++ : $sectionsById[$sectionId]['hypothesis_total']++;

            if ($row['year_in_branch'] !== null) {
                $sectionsById[$sectionId]['year_segments'][$row['year_in_branch']] =
                    ($sectionsById[$sectionId]['year_segments'][$row['year_in_branch']] ?? 0) + 1;
            }
        }

        ksort($pyramid);

        // Hidden sections are in the map so that an assignment to one is
        // never lost (see getForecast()'s own note), but a hidden section
        // nobody is projected into stays off the page — it is hidden for a
        // reason and has nothing to say here.
        $visibleSections = [];
        foreach ($sectionsById as $section) {
            if ($section['is_visible'] || $section['total'] > 0) {
                unset($section['is_visible']);
                $visibleSections[] = $section;
            }
        }

        // $projected already includes unassigned rows (section_id null) —
        // pushed unconditionally above so the pyramid picks them up too, so
        // count($projected) alone is the true unit total; adding
        // $unassignedTotal on top would double it.
        $unassignedTotal = $unassignedFromPassage + $unassignedFromRegistration + $unassignedFromUnknownSection;
        $projectedTotal = count($projected);

        return [
            'summary' => [
                'projected_total' => $projectedTotal,
                'current_total' => $currentTotal,
                'variation' => $projectedTotal - $currentTotal,
                'departures_count' => $departuresCount,
                'aging_out_count' => $agingOutCount,
                'new_registrations_count' => $newRegistrationsCount,
            ],
            'sections' => $visibleSections,
            'unassigned' => [
                'total' => $unassignedTotal,
                'from_passage' => $unassignedFromPassage,
                'from_registration' => $unassignedFromRegistration,
                'from_unknown_section' => $unassignedFromUnknownSection,
            ],
            'pyramid' => $pyramid,
            'pyramid_max' => $pyramidMax,
        ];
    }

    /**
     * Count of current-year animés marked leaving — unit-wide, non-staff.
     * A dedicated query (not a reuse of getAnimeMemberYears(), which
     * excludes leaving=1 rows by design) since this is the one place that
     * needs exactly the opposite set. Same non-staff role filter as
     * getAnimeMemberYears() (chief/admin/intendant excluded).
     */
    public function countDeparturesForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT my.id)
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND my.leaving = 1
               AND f.role NOT IN ('chief', 'admin', 'intendant') AND mf.section_id IS NOT NULL"
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    private function totalYearsForBranchSortOrder(int $sortOrder): int
    {
        $branch = MemberYearService::branchForSortOrder($sortOrder);

        return $branch !== null ? $branch['age_max'] - $branch['age_min'] + 1 : 4;
    }

    private function classifyGender(?string $gender): string
    {
        $g = mb_strtolower(trim((string) $gender));
        if ($g === '') {
            return 'other';
        }
        if ($g === 'm' || $g === 'h' || $g === 'g'
            || str_contains($g, 'gar') || str_contains($g, 'masc') || str_contains($g, 'homme')) {
            return 'male';
        }
        if ($g === 'f' || str_contains($g, 'fille') || str_contains($g, 'fem')) {
            return 'female';
        }
        return 'other';
    }
}
