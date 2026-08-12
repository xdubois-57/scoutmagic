<?php

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

        $rows = [];
        foreach ($this->requestRepository->findAcceptedForYear($targetYearId) as $request) {
            $slot = SlotMath::slotForBirthDate($brackets, $request->birthDate, $referenceYear);

            $siblings = [];
            foreach ($this->requestRepository->findSiblingMemberIds($request->id) as $siblingMemberId) {
                $siblings[] = $this->resolveCurrentMemberNameAndSection($siblingMemberId, $currentPublicYearId);
            }

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

        $promoted = [];
        foreach ($candidates as $row) {
            $birthYear = MemberYearService::extractBirthYear(
                $row['birth_date_encrypted'] !== null ? $this->encryption->decrypt($row['birth_date_encrypted']) : null
            );
            $effectiveAge = $memberYearService->getEffectiveAge($birthYear, (int) $row['scout_year_offset'], $referenceYear);

            // Only the last rank of a branch changes branch at all; a
            // still-mid-branch animé stays exactly where they are.
            if (!$effectiveAge->isInKnownBranch() || $effectiveAge->yearInBranch !== $effectiveAge->totalYearsInBranch) {
                continue;
            }

            $currentSortOrder = AgeBranchRepository::canonicalSortOrder((string) $effectiveAge->branchName);
            // Pionniers (40) is the last animés branch — "un pionnier de
            // dernière année ne passe nulle part" (module spec), never a
            // promotion candidate.
            if ($currentSortOrder >= 40) {
                continue;
            }
            $nextSortOrder = $currentSortOrder + 10;

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
                'household' => $this->resolveHousehold($memberYearId, $currentPublicYearId),
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
     * Persists the destination for every branch-change member who has
     * exactly one possible destination section and no destination chosen
     * yet — there is no real decision to make (module spec's own "if
     * there's only one possible section, just assign it" rule), so this
     * saves staff from a pointless click.
     *
     * Deliberately a SEPARATE step from getBranchChanges() itself, called
     * only from Controller\PassageController::index() — that method is
     * also called by Service\ForecastService (explicitly read-only, module
     * spec: "no route writes anything") and by PassageController's own
     * arrivalSectionIdsForMember() (with a throwaway target year id of 0,
     * only ever used to read destination_options back out). Auto-writing
     * inside getBranchChanges() itself would fire on both of those too.
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
     * Every animé (active, non-leaving, non-staff — f.role NOT IN
     * ('chief','admin','intendant'), the same trap Core\Member\
     * SectionService::getSectionStaff() already guards against — and the
     * same 'intendant' omission Core\Member\SectionService::
     * getSectionAnimes() itself once had, fixed there too) for a given
     * scout year,
     * one row per member (main function preferred when several non-staff
     * functions exist). Unit-wide, not scoped to one section.
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
             ORDER BY mf.is_main_function DESC"
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
     * Everyone else sharing at least one of $memberYearId's own address
     * blind indexes ("même adresse" — module spec, NEVER "fratrie"),
     * active, non-leaving, same scout year. Any role (a household can
     * include a staff sibling too).
     *
     * @return array<int, array{name: string, section_label: ?string}>
     */
    private function resolveHousehold(int $memberYearId, int $scoutYearId): array
    {
        $blindStmt = $this->pdo->prepare(
            'SELECT DISTINCT address_normalized_blind_index FROM member_addresses
             WHERE member_year_id = ? AND address_normalized_blind_index IS NOT NULL'
        );
        $blindStmt->execute([$memberYearId]);
        $blindIndexes = $blindStmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($blindIndexes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($blindIndexes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT my2.id
             FROM member_addresses ma2
             JOIN member_years my2 ON my2.id = ma2.member_year_id
             WHERE ma2.address_normalized_blind_index IN ({$placeholders})
               AND my2.scout_year_id = ? AND my2.is_active = 1 AND my2.leaving = 0 AND my2.id != ?"
        );
        $stmt->execute([...array_values($blindIndexes), $scoutYearId, $memberYearId]);

        $household = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $otherMemberYearId) {
            $household[] = $this->resolveMemberYearNameAndSection((int) $otherMemberYearId);
        }

        return $household;
    }

    /**
     * @return array{name: string, section_label: ?string}
     */
    private function resolveMemberYearNameAndSection(int $memberYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.first_name_encrypted, my.last_name_encrypted, s.name, s.desk_code
             FROM member_years my
             LEFT JOIN member_functions mf ON mf.member_year_id = my.id
             LEFT JOIN sections s ON s.id = mf.section_id
             WHERE my.id = ?
             ORDER BY mf.is_main_function DESC'
        );
        $stmt->execute([$memberYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['name' => '?', 'section_label' => null];
        }

        return [
            'name' => $this->decryptName($row['first_name_encrypted'], $row['last_name_encrypted']),
            'section_label' => $row['name'] ?? $row['desk_code'] ?? null,
        ];
    }

    /**
     * A registration request's sibling link references a real member —
     * resolved against the CURRENT public year (not the target year: a
     * sibling is already enrolled today, that's the whole point of the
     * link). Falls back to a placeholder if the member has no row for
     * that year (a fringe case: a Desk-side departure between submission
     * and the Passage page being viewed).
     *
     * @return array{name: string, section_label: ?string}
     */
    private function resolveCurrentMemberNameAndSection(int $memberId, int $currentPublicYearId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM member_years WHERE member_id = ? AND scout_year_id = ? AND is_active = 1');
        $stmt->execute([$memberId, $currentPublicYearId]);
        $memberYearId = $stmt->fetchColumn();
        if ($memberYearId === false) {
            return ['name' => '?', 'section_label' => null];
        }

        return $this->resolveMemberYearNameAndSection((int) $memberYearId);
    }

    private function decryptName($firstNameEncrypted, $lastNameEncrypted): string
    {
        $first = $firstNameEncrypted !== null ? $this->encryption->decrypt($firstNameEncrypted) : '';
        $last = $lastNameEncrypted !== null ? $this->encryption->decrypt($lastNameEncrypted) : '';

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
