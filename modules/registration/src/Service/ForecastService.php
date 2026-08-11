<?php

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
     *   summary: array{projected_total: int, current_total: int, variation: int, departures_count: int, new_registrations_count: int},
     *   sections: array<int, array{id: int, label: string, color: string, total_years_in_branch: int, year_segments: array<int, int>, gender: array{male: int, female: int, other: int, total: int}, total: int, certain_total: int, hypothesis_total: int}>,
     *   unassigned: array{total: int, from_passage: int, from_registration: int},
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
        $sections = $this->sectionService->getAllWithBranches();

        /** @var array<int, array{section_id: ?int, year_in_branch: ?int, gender: string, birth_year: ?int, certain: bool}> $projected */
        $projected = [];
        $unassignedFromPassage = 0;
        $unassignedFromRegistration = 0;

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
            if (!$currentAge->isInKnownBranch() || $currentAge->yearInBranch === $currentAge->totalYearsInBranch) {
                continue; // last rank — a Service\PassageService::getBranchChanges() candidate instead, see (c)
            }

            $projectedAge = $this->memberYearService->getEffectiveAge($birthYear, (int) $row['scout_year_offset'], $targetReferenceYear);
            if (!$projectedAge->isInKnownBranch()) {
                continue;
            }

            $projected[] = [
                'section_id' => $row['section_id'] !== null ? (int) $row['section_id'] : null,
                'year_in_branch' => $projectedAge->yearInBranch,
                'gender' => $this->classifyGender(
                    $row['gender_encrypted'] !== null ? $this->encryption->decrypt($row['gender_encrypted']) : null
                ),
                'birth_year' => $birthYear,
                'certain' => false,
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
                if ($destinationSectionId === null) {
                    $unassignedFromPassage++;
                }

                $projected[] = [
                    // Still counted in the unit total and the pyramid when
                    // unassigned (null here) — module spec: "la pyramide et
                    // les totaux doivent inclure les non attribués". Only
                    // the per-section breakdown skips them (buildViewModel).
                    'section_id' => $destinationSectionId !== null ? (int) $destinationSectionId : null,
                    'year_in_branch' => 1, // always the bottom rank of the arrival branch
                    'gender' => null, // resolved separately below, keyed by member_id (see resolveGenderAndBirthYear)
                    'birth_year' => null,
                    'certain' => false,
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
            $sectionId = $request->intendedSectionId;
            if ($sectionId === null) {
                $unassignedFromRegistration++;
            }

            $projected[] = [
                // Still counted in the unit total and the pyramid when
                // unassigned (null here) — see the matching (c) comment above.
                'section_id' => $sectionId,
                'year_in_branch' => $row['slot']['year_in_branch'] ?? null,
                'gender' => $this->classifyGender($request->gender),
                'birth_year' => $request->birthYear(),
                'certain' => false,
            ];
        }

        // (c)'s rows deferred their gender/birth year lookup (they share the
        // exact address/name resolution PassageService already did — no
        // point decrypting the same row twice); resolve them now in one pass.
        $projected = $this->resolveDeferredGenderAndBirthYear($projected, $currentYearId);

        return $this->buildViewModel(
            $projected,
            $sections,
            $unassignedFromPassage,
            $unassignedFromRegistration,
            $this->countKnownBranchAnimes($currentAnimes, $currentReferenceYear),
            $this->countDeparturesForYear($currentYearId),
            count($newRegistrations)
        );
    }

    /**
     * Current-year animés whose effective age falls in one of the four
     * animés branches — the same population the projection itself is
     * built from (buckets a-d never include a Route/Iama-branch non-staff
     * member), so "current_total" and "projected_total" compare like with
     * like.
     *
     * @param array<int, array<string, mixed>> $currentAnimes
     */
    private function countKnownBranchAnimes(array $currentAnimes, int $currentReferenceYear): int
    {
        $count = 0;
        foreach ($currentAnimes as $row) {
            $birthYear = MemberYearService::extractBirthYear(
                $row['birth_date_encrypted'] !== null ? $this->encryption->decrypt($row['birth_date_encrypted']) : null
            );
            $age = $this->memberYearService->getEffectiveAge($birthYear, (int) $row['scout_year_offset'], $currentReferenceYear);
            if ($age->isInKnownBranch()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * (c)'s branch-change rows only carry a member_id — this resolves their
     * gender/birth year in one extra pass rather than re-querying per row.
     *
     * @param array<int, array<string, mixed>> $projected
     * @return array<int, array<string, mixed>>
     */
    private function resolveDeferredGenderAndBirthYear(array $projected, int $currentYearId): array
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
            "SELECT member_id, gender_encrypted, birth_date_encrypted FROM member_years
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
            $row['birth_year'] = $source !== null
                ? MemberYearService::extractBirthYear($source['birth_date_encrypted'] !== null ? $this->encryption->decrypt($source['birth_date_encrypted']) : null)
                : null;
            unset($row['member_id']);
        }
        unset($row);

        return $projected;
    }

    /**
     * @param array<int, array{section_id: ?int, year_in_branch: ?int, gender: ?string, birth_year: ?int, certain: bool}> $projected
     * @param array<int, array{id: int, desk_code: string, name: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string}> $sections
     * @return array{
     *   summary: array{projected_total: int, current_total: int, variation: int, departures_count: int, new_registrations_count: int},
     *   sections: array<int, array{id: int, label: string, color: string, total_years_in_branch: int, year_segments: array<int, int>, gender: array{male: int, female: int, other: int, total: int}, total: int, certain_total: int, hypothesis_total: int}>,
     *   unassigned: array{total: int, from_passage: int, from_registration: int},
     *   pyramid: array<int, array{male: int, female: int, other: int}>,
     *   pyramid_max: int
     * }
     */
    private function buildViewModel(
        array $projected,
        array $sections,
        int $unassignedFromPassage,
        int $unassignedFromRegistration,
        int $currentTotal,
        int $departuresCount,
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
            ];
        }

        $pyramid = [];
        $pyramidMax = 0;

        foreach ($projected as $row) {
            if ($row['birth_year'] !== null) {
                $pyramid[$row['birth_year']] ??= ['male' => 0, 'female' => 0, 'other' => 0];
                $pyramid[$row['birth_year']][$row['gender']]++;
                $pyramidMax = max($pyramidMax, $pyramid[$row['birth_year']]['male'], $pyramid[$row['birth_year']]['female']);
            }

            $sectionId = $row['section_id'];
            if ($sectionId === null || !isset($sectionsById[$sectionId])) {
                continue; // shouldn't happen — a chosen destination/section prévue is always a real, active section
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

        // $projected already includes unassigned rows (section_id null) —
        // pushed unconditionally above so the pyramid picks them up too, so
        // count($projected) alone is the true unit total; adding
        // $unassignedTotal on top would double it.
        $unassignedTotal = $unassignedFromPassage + $unassignedFromRegistration;
        $projectedTotal = count($projected);

        return [
            'summary' => [
                'projected_total' => $projectedTotal,
                'current_total' => $currentTotal,
                'variation' => $projectedTotal - $currentTotal,
                'departures_count' => $departuresCount,
                'new_registrations_count' => $newRegistrationsCount,
            ],
            'sections' => array_values($sectionsById),
            'unassigned' => [
                'total' => $unassignedTotal,
                'from_passage' => $unassignedFromPassage,
                'from_registration' => $unassignedFromRegistration,
            ],
            'pyramid' => $pyramid,
            'pyramid_max' => $pyramidMax,
        ];
    }

    /**
     * Count of current-year animés marked leaving — unit-wide, non-staff.
     * A dedicated query (not a reuse of getAnimeMemberYears(), which
     * excludes leaving=1 rows by design) since this is the one place that
     * needs exactly the opposite set.
     */
    public function countDeparturesForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT my.id)
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND my.leaving = 1
               AND f.role NOT IN ('chief', 'admin') AND mf.section_id IS NOT NULL"
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
