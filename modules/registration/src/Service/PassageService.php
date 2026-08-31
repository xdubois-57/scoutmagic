<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Import\AgeBranchRepository;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;

/**
 * "Passage" page's own domain logic (module spec, iteration 6): the two
 * blocks are deliberately kept apart, since they answer two different
 * questions —
 *
 * - getNewRegistrations(): accepted-but-not-yet-encoded requests for the
 *   TARGET year, with their declared sibling links (each sibling's OWN
 *   current section, resolved against the CURRENT public year — a
 *   sibling is a real, already-enrolled member).
 * - getBranchChanges(): existing members changing branch, resolved
 *   entirely against the CURRENT public year (nothing here reads the
 *   target year except to look up any destination already picked).
 *
 * "Household" (bottom block) and "fratrie" (top block) are two distinct,
 * never-conflated notions (module spec's own explicit warning): a shared
 * normalized address means "same household" — roommates, two families at
 * one number, shared custody — never "siblings"; a sibling link is a
 * parent's own explicit declaration at submission time. Never mix the two
 * lookups.
 */
class PassageService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private SectionService $sectionService,
        private SectionTransferRepository $transferRepository,
        private RegistrationRequestRepository $requestRepository,
        private AgeBracketRepository $ageBracketRepository
    ) {
    }

    /**
     * @return array<int, array{
     *   request: \Modules\Registration\Repository\RegistrationRequest,
     *   slot: ?array{age_branch_id: int, year_in_branch: int},
     *   slot_label: string,
     *   desired_section_label: ?string,
     *   siblings: array<int, array{name: string, section_label: ?string}>,
     *   sections_in_branch: array<int, array{id: int, name: ?string, desk_code: string}>
     * }>
     */
    public function getNewRegistrations(int $targetYearId, string $targetYearLabel, string $referenceMonthDay, int $currentPublicYearId): array
    {
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($targetYearLabel),
            $referenceMonthDay
        );

        $sections = $this->sectionService->getAllWithBranches(true);
        $sectionLabels = [];
        foreach ($sections as $section) {
            $sectionLabels[$section['id']] = $section['name'] ?? $section['desk_code'];
        }

        $requests = $this->requestRepository->findAcceptedForYear($targetYearId);

        // Siblings for EVERY request, then their names/sections, in two
        // queries rather than one per request plus two per sibling.
        $siblingIdsByRequest = $this->requestRepository->findSiblingMemberIdsForRequests(
            array_map(static fn($r) => $r->id, $requests)
        );
        $siblingsByRequest = $this->resolveSiblingsByRequest($siblingIdsByRequest, $currentPublicYearId);

        $rows = [];
        foreach ($requests as $request) {
            $slot = SlotMath::slotForBirthDate($brackets, $request->birthDate, $referenceYear);

            $siblings = $siblingsByRequest[$request->id] ?? [];

            $sectionsInBranch = $slot !== null
                ? array_values(array_filter($sections, static fn(array $s) => $s['age_branch_id'] === $slot['age_branch_id']))
                : [];

            $rows[] = [
                'request' => $request,
                'slot' => $slot,
                'slot_label' => $this->slotLabel($brackets, $slot),
                'desired_section_label' => $request->desiredSectionId !== null
                    ? ($sectionLabels[$request->desiredSectionId] ?? '—')
                    : null,
                'siblings' => $siblings,
                'sections_in_branch' => $sectionsInBranch,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{
     *   section_label: string,
     *   members: array<int, array{
     *     member_id: int, name: string, branch_year_label: string,
     *     household: array<int, array{name: string, section_label: ?string}>,
     *     destination_section_id: ?int,
     *     destination_options: array<int, array{id: int, name: ?string, desk_code: string}>
     *   }>
     * }>
     */
    public function getBranchChanges(int $currentPublicYearId, string $currentPublicYearLabel, int $targetYearId): array
    {
        $sections = $this->sectionService->getAllWithBranches(true);
        $sectionLabels = [];
        $sectionsBySortOrder = [];
        foreach ($sections as $section) {
            $sectionLabels[$section['id']] = $section['name'] ?? $section['desk_code'];
            $sectionsBySortOrder[$section['branch_sort_order']][] = $section;
        }

        $referenceYear = MemberYearService::referenceYearFromScoutYearLabel($currentPublicYearLabel);
        $memberYearService = new MemberYearService();

        $candidates = $this->getAnimeMemberYears($currentPublicYearId);

        // Households for every candidate at once. Resolving them one member
        // at a time cost two queries per promoted animé plus one per
        // neighbour found — on a unit of any size, that was the bulk of this
        // page's load time.
        $households = $this->resolveHouseholds(
            array_map(static fn(array $row) => (int) $row['member_year_id'], $candidates),
            $currentPublicYearId
        );

        $promoted = [];
        foreach ($candidates as $row) {
            $birthYear = MemberYearService::extractBirthYear(
                $row['birth_date_encrypted'] !== null ? $this->encryption->decrypt($row['birth_date_encrypted'], 'member_years.birth_date') : null
            );
            $effectiveAge = $memberYearService->getEffectiveAge($birthYear, (int) $row['scout_year_offset'], $referenceYear);

            // Only the last rank of a branch changes branch at all (and a
            // last-year Pionnier "ne passe nulle part") — one single
            // expression of that rule, shared with
            // arrivalSectionsForMember() so the page and the save-time check
            // can never disagree about who may go where.
            $nextSortOrder = $this->arrivalSortOrderFor($effectiveAge);
            if ($nextSortOrder === null) {
                continue;
            }

            $memberId = (int) $row['member_id'];
            $memberYearId = (int) $row['member_year_id'];
            $currentSectionId = $row['section_id'] !== null ? (int) $row['section_id'] : null;

            $promoted[] = [
                'member_id' => $memberId,
                'member_year_id' => $memberYearId,
                'name' => $this->decryptName($row['first_name_encrypted'], $row['last_name_encrypted']),
                'branch_year_label' => $effectiveAge->getBranchYearLabel(),
                'current_section_id' => $currentSectionId,
                'current_section_label' => $currentSectionId !== null ? ($sectionLabels[$currentSectionId] ?? '—') : '—',
                'household' => $households[$memberYearId] ?? [],
                'destination_options' => $sectionsBySortOrder[$nextSortOrder] ?? [],
            ];
        }

        $memberIds = array_column($promoted, 'member_id');
        $destinations = $this->transferRepository->findDestinationsForMembers($memberIds, $targetYearId);

        $grouped = [];
        foreach ($promoted as $entry) {
            $key = (string) ($entry['current_section_id'] ?? 'none');
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['section_label' => $entry['current_section_label'], 'members' => []];
            }
            $grouped[$key]['members'][] = [
                'member_id' => $entry['member_id'],
                'name' => $entry['name'],
                'branch_year_label' => $entry['branch_year_label'],
                'household' => $entry['household'],
                'destination_section_id' => $destinations[$entry['member_id']] ?? null,
                'destination_options' => $entry['destination_options'],
            ];
        }

        // Branch (then desk_code) order — the same convention every other
        // section listing on the site follows (SectionService::
        // getAllWithBranches(), ARCHITECTURE.md §8.8), never the raw
        // section id a plain ksort() on the string keys above would use.
        // "none" (no current section) has no entry in $sectionSortKeys and
        // sorts last.
        $sectionSortKeys = [];
        foreach ($sections as $section) {
            $sectionSortKeys[$section['id']] = [$section['branch_sort_order'], $section['desk_code']];
        }
        uksort($grouped, static function (string $a, string $b) use ($sectionSortKeys): int {
            $keyA = $sectionSortKeys[(int) $a] ?? [PHP_INT_MAX, ''];
            $keyB = $sectionSortKeys[(int) $b] ?? [PHP_INT_MAX, ''];
            return $keyA <=> $keyB;
        });

        return $grouped;
    }

    /**
     * The sections a single member may be sent to — the arrival branch of
     * ONE animé, without rebuilding the whole page.
     *
     * Controller\PassageController used to answer "is this destination
     * allowed?" by running getBranchChanges() in full — decrypting every
     * animé of the year and resolving every household — on each save. The
     * promotion rule itself (last rank of a branch, Pionniers excluded)
     * lives here and is shared with getBranchChanges(), so the two can never
     * disagree about who may go where.
     *
     * @return array<int, array{id: int, name: ?string, desk_code: string}>
     */
    public function arrivalSectionsForMember(
        int $memberId,
        int $currentPublicYearId,
        string $currentPublicYearLabel,
        bool $includeHidden = true
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT my.birth_date_encrypted, my.scout_year_offset
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.member_id = ? AND my.scout_year_id = ? AND my.is_active = 1 AND my.leaving = 0
               AND f.role NOT IN ('chief', 'admin', 'intendant') AND mf.section_id IS NOT NULL
             ORDER BY mf.is_main_function DESC, mf.id ASC
             LIMIT 1"
        );
        $stmt->execute([$memberId, $currentPublicYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }

        $nextSortOrder = $this->arrivalBranchSortOrder(
            $row['birth_date_encrypted'] !== null ? $this->encryption->decrypt($row['birth_date_encrypted'], 'member_years.birth_date') : null,
            (int) $row['scout_year_offset'],
            MemberYearService::referenceYearFromScoutYearLabel($currentPublicYearLabel)
        );
        if ($nextSortOrder === null) {
            return [];
        }

        // includeHidden TRUE for the Passage page, which offers a
        // hidden-but-active section as an ordinary destination; FALSE for
        // the family form, where the question « dans quelle section ? » is
        // only asked when the family has a real choice among the sections
        // they can actually see (roadmap IT-14).
        $sections = [];
        foreach ($this->sectionService->getAllWithBranches($includeHidden) as $section) {
            if ($section['branch_sort_order'] === $nextSortOrder) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * The `branch_sort_order` a member is heading INTO next year, or null
     * when they aren't changing branch at all — the single expression of
     * the module spec's promotion rule: only the last rank of a branch moves
     * up, and "un pionnier de dernière année ne passe nulle part" (40 is the
     * last animés branch).
     */
    private function arrivalBranchSortOrder(?string $birthDate, int $scoutYearOffset, int $referenceYear): ?int
    {
        return $this->arrivalSortOrderFor((new MemberYearService())->getEffectiveAge(
            MemberYearService::extractBirthYear($birthDate),
            $scoutYearOffset,
            $referenceYear
        ));
    }

    /**
     * Same rule, for a caller that already computed the effective age (and
     * needs it for other things too, like the branch-year label).
     */
    private function arrivalSortOrderFor(\Core\Member\EffectiveAge $effectiveAge): ?int
    {
        if (!$effectiveAge->isInKnownBranch() || $effectiveAge->yearInBranch !== $effectiveAge->totalYearsInBranch) {
            return null;
        }

        $currentSortOrder = AgeBranchRepository::canonicalSortOrder((string) $effectiveAge->branchName);
        if ($currentSortOrder >= 40) {
            return null;
        }

        return $currentSortOrder + 10;
    }

    /**
     * Persists the destination for every branch-change member who has
     * exactly one possible destination section and no destination chosen
     * yet — there is no real decision to make (module spec's own "if
     * there's only one possible section, just assign it" rule), so this
     * saves staff from a pointless click.
     *
     * Deliberately a SEPARATE step from getBranchChanges() itself, whose
     * other callers must never write: Service\ForecastService is explicitly
     * read-only (module spec: "no route writes anything").
     *
     * Called from Task\AutoAssignPassageHandler, never from the page's own
     * render path — Controller\PassageController::index() used to call it on
     * every display, which made a plain GET write to the database.
     *
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $branchChanges
     * @return array<string, array{section_label: string, members: array<int, array<string, mixed>>}>
     */
    public function autoAssignSingleOptionDestinations(array $branchChanges, int $targetYearId): array
    {
        foreach ($branchChanges as $key => $group) {
            foreach ($group['members'] as $index => $member) {
                if ($member['destination_section_id'] !== null || count($member['destination_options']) !== 1) {
                    continue;
                }

                $onlyOption = $member['destination_options'][0];
                $this->transferRepository->setDestination($member['member_id'], $targetYearId, $onlyOption['id']);
                $branchChanges[$key]['members'][$index]['destination_section_id'] = $onlyOption['id'];
            }
        }

        return $branchChanges;
    }

    /**
     * How many rows autoAssignSingleOptionDestinations() would actually
     * write — so Task\AutoAssignPassageHandler can journal a real count
     * instead of logging on every hourly run whether or not anything
     * changed.
     *
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $branchChanges
     */
    public function countSingleOptionDestinationsToAssign(array $branchChanges): int
    {
        $count = 0;
        foreach ($branchChanges as $group) {
            foreach ($group['members'] as $member) {
                if ($member['destination_section_id'] === null && count($member['destination_options']) === 1) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Every animé (active, non-leaving, non-staff — f.role NOT IN
     * ('chief','admin','intendant'), the same trap Core\Member\
     * SectionService::getSectionStaff() already guards against — and the
     * same 'intendant' omission Core\Member\SectionService::
     * getSectionAnimes() itself once had, fixed there too) for a given
     * scout year,
     * one row per member (main function preferred when several non-staff
     * functions exist, then lowest `mf.id` — without that second tie-break
     * the winning row was whatever order the database happened to return,
     * so a member with several non-staff functions and no main one could
     * change section between two page loads). Unit-wide, not scoped to one
     * section.
     *
     * Public and reused by Service\ForecastService (module spec iteration
     * 7): the "Prévisions" page's continuing-members projection needs the
     * exact same roster this method already builds for branch-change
     * detection, just for either scout year (current or target) rather
     * than only the current public one — one query, two callers, never a
     * second near-copy of this SQL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAnimeMemberYears(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT my.id AS member_year_id, my.member_id, my.first_name_encrypted, my.last_name_encrypted,
                    my.birth_date_encrypted, my.gender_encrypted, my.scout_year_offset, mf.section_id
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND my.leaving = 0
               AND f.role NOT IN ('chief', 'admin', 'intendant') AND mf.section_id IS NOT NULL
             ORDER BY my.id, mf.is_main_function DESC, mf.id ASC"
        );
        $stmt->execute([$scoutYearId]);

        $byMemberYear = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberYearId = (int) $row['member_year_id'];
            if (!isset($byMemberYear[$memberYearId])) {
                $byMemberYear[$memberYearId] = $row;
            }
        }

        return array_values($byMemberYear);
    }

    /**
     * For each of $memberYearIds, everyone else sharing at least one of its
     * address blind indexes ("même adresse" — module spec, NEVER "fratrie"),
     * active, non-leaving, same scout year. Any role (a household can
     * include a staff sibling too).
     *
     * Three queries for the whole page, whatever the roster size: the blind
     * indexes of everybody, then every member_year sitting at any of those
     * addresses, then all their names/sections in one go. The per-member
     * version this replaces issued 2 queries per animé plus 1 per neighbour.
     *
     * @param array<int> $memberYearIds
     * @return array<int, array<int, array{name: string, section_label: ?string}>> keyed by member_year_id
     */
    private function resolveHouseholds(array $memberYearIds, int $scoutYearId): array
    {
        $memberYearIds = array_values(array_unique($memberYearIds));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $blindStmt = $this->pdo->prepare(
            "SELECT DISTINCT member_year_id, address_normalized_blind_index FROM member_addresses
             WHERE member_year_id IN ({$placeholders}) AND address_normalized_blind_index IS NOT NULL"
        );
        $blindStmt->execute($memberYearIds);

        /** @var array<int, array<int, string>> $blindsByMemberYear */
        $blindsByMemberYear = [];
        $allBlinds = [];
        foreach ($blindStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $blind = (string) $row['address_normalized_blind_index'];
            $blindsByMemberYear[(int) $row['member_year_id']][] = $blind;
            $allBlinds[$blind] = true;
        }
        if ($allBlinds === []) {
            return [];
        }

        $blindList = array_keys($allBlinds);
        $blindPlaceholders = implode(',', array_fill(0, count($blindList), '?'));
        $occupantStmt = $this->pdo->prepare(
            "SELECT DISTINCT ma2.address_normalized_blind_index AS blind, my2.id AS member_year_id
             FROM member_addresses ma2
             JOIN member_years my2 ON my2.id = ma2.member_year_id
             WHERE ma2.address_normalized_blind_index IN ({$blindPlaceholders})
               AND my2.scout_year_id = ? AND my2.is_active = 1 AND my2.leaving = 0"
        );
        $occupantStmt->execute([...$blindList, $scoutYearId]);

        /** @var array<string, array<int, int>> $occupantsByBlind */
        $occupantsByBlind = [];
        $allOccupantIds = [];
        foreach ($occupantStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $occupantId = (int) $row['member_year_id'];
            $occupantsByBlind[(string) $row['blind']][] = $occupantId;
            $allOccupantIds[$occupantId] = true;
        }

        $labels = $this->resolveMemberYearNamesAndSections(array_keys($allOccupantIds));

        $households = [];
        foreach ($blindsByMemberYear as $memberYearId => $blinds) {
            $seen = [];
            foreach ($blinds as $blind) {
                foreach ($occupantsByBlind[$blind] ?? [] as $occupantId) {
                    // Never themselves, and never twice when two people share
                    // more than one address.
                    if ($occupantId === $memberYearId || isset($seen[$occupantId])) {
                        continue;
                    }
                    $seen[$occupantId] = true;
                    $households[$memberYearId][] = $labels[$occupantId] ?? ['name' => '?', 'section_label' => null];
                }
            }
        }

        return $households;
    }

    /**
     * Names and section labels for a batch of member_years, one query.
     *
     * The tie-break on `mf.id` matters: a member with several non-staff
     * functions and no main one left the winning row to whatever order the
     * database happened to return, so the same person could show a different
     * section between two page loads (and, through getAnimeMemberYears()
     * below, land in a different group entirely).
     *
     * @param array<int> $memberYearIds
     * @return array<int, array{name: string, section_label: ?string}>
     */
    private function resolveMemberYearNamesAndSections(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique($memberYearIds));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT my.id, my.first_name_encrypted, my.last_name_encrypted, s.name, s.desk_code
             FROM member_years my
             LEFT JOIN member_functions mf ON mf.member_year_id = my.id
             LEFT JOIN sections s ON s.id = mf.section_id
             WHERE my.id IN ({$placeholders})
             ORDER BY my.id, mf.is_main_function DESC, mf.id ASC"
        );
        $stmt->execute($memberYearIds);

        $labels = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (isset($labels[$id])) {
                continue; // first row wins — main function, then lowest mf.id
            }
            $labels[$id] = [
                'name' => $this->decryptName($row['first_name_encrypted'], $row['last_name_encrypted']),
                'section_label' => $row['name'] ?? $row['desk_code'] ?? null,
            ];
        }

        return $labels;
    }

    /**
     * A registration request's sibling links reference real members —
     * resolved against the CURRENT public year (not the target year: a
     * sibling is already enrolled today, that's the whole point of the
     * link). A member with no row for that year falls back to a placeholder
     * (a fringe case: a Desk-side departure between submission and the
     * Passage page being viewed) rather than disappearing.
     *
     * @param array<int, array<int>> $siblingIdsByRequest request id => member ids
     * @return array<int, array<int, array{name: string, section_label: ?string}>> keyed by request id
     */
    private function resolveSiblingsByRequest(array $siblingIdsByRequest, int $currentPublicYearId): array
    {
        $allMemberIds = [];
        foreach ($siblingIdsByRequest as $memberIds) {
            foreach ($memberIds as $memberId) {
                $allMemberIds[$memberId] = true;
            }
        }
        if ($allMemberIds === []) {
            return [];
        }

        $memberIds = array_keys($allMemberIds);
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, id FROM member_years
             WHERE member_id IN ({$placeholders}) AND scout_year_id = ? AND is_active = 1"
        );
        $stmt->execute([...$memberIds, $currentPublicYearId]);

        $memberYearIdByMember = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberYearIdByMember[(int) $row['member_id']] = (int) $row['id'];
        }

        $labels = $this->resolveMemberYearNamesAndSections(array_values($memberYearIdByMember));

        $result = [];
        foreach ($siblingIdsByRequest as $requestId => $siblingMemberIds) {
            foreach ($siblingMemberIds as $siblingMemberId) {
                $memberYearId = $memberYearIdByMember[$siblingMemberId] ?? null;
                $result[$requestId][] = $memberYearId !== null
                    ? ($labels[$memberYearId] ?? ['name' => '?', 'section_label' => null])
                    : ['name' => '?', 'section_label' => null];
            }
        }

        return $result;
    }

    private function decryptName($firstNameEncrypted, $lastNameEncrypted): string
    {
        $first = $firstNameEncrypted !== null ? $this->encryption->decrypt($firstNameEncrypted, 'member_years.first_name') : '';
        $last = $lastNameEncrypted !== null ? $this->encryption->decrypt($lastNameEncrypted, 'member_years.last_name') : '';

        return trim($first . ' ' . $last);
    }

    /**
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     * @param array{age_branch_id: int, year_in_branch: int}|null $slot
     */
    private function slotLabel(array $brackets, ?array $slot): string
    {
        if ($slot === null) {
            return 'Non déterminé';
        }
        foreach ($brackets as $bracket) {
            if ($bracket->ageBranchId === $slot['age_branch_id']) {
                return $bracket->branchLabel . ' — ' . $slot['year_in_branch'] . 'ᵉ année';
            }
        }

        return 'Non déterminé';
    }
}
