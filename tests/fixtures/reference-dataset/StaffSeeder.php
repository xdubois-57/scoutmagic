<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Import\FunctionRepository;
use Core\Member\SectionService;
use Modules\Trombinoscope\Repository\FunctionFlagsRepository;

/**
 * Gives every section a responsable and its two badges.
 *
 * The badges go through Core\Badge — `ensureDefaults()` and
 * `syncSectionReferentBadges()` first, both idempotent and both what a first
 * load of the badge page does, then Repository\MemberBadgeRepository::assign()
 * per member-year. The lead flag goes through the trombinoscope module's own
 * Repository\FunctionFlagsRepository::setLead(), the call its configuration
 * page makes.
 *
 * **The coverage check is the point of the return value.** A flag on a
 * function nobody holds and a section whose staff nobody leads look identical
 * on the page — an empty "responsable" slot — and both are silent. So this
 * counts, per year, the sections that ended up with a lead and the ones that
 * did not, and hands the list back for the builder to print.
 */
final class StaffSeeder
{
    private readonly BadgeRepository $badgeRepository;

    private readonly MemberBadgeRepository $memberBadgeRepository;

    private readonly BadgeService $badgeService;

    private readonly FunctionRepository $functionRepository;

    /**
     * @param array<string, int> $sectionIds section handle => sections.id
     * @param array<string, int> $yearIds    scout year label => scout_years.id
     * @param array<string, int> $memberIds  Tiers => members.id
     */
    public function __construct(
        private readonly \PDO $pdo,
        SectionService $sectionService,
        private readonly array $sectionIds,
        private readonly array $yearIds,
        private readonly array $memberIds,
        private readonly ?int $actorId,
    ) {
        $this->badgeRepository = new BadgeRepository($pdo);
        $this->memberBadgeRepository = new MemberBadgeRepository($pdo);
        $this->badgeService = new BadgeService($this->badgeRepository, $this->memberBadgeRepository, $sectionService);
        $this->functionRepository = new FunctionRepository($pdo);
    }

    /**
     * Badges only — the lead flag belongs to the trombinoscope module and is
     * applied separately, so that an instance with that module disabled still
     * gets its badges.
     *
     * @return int the number of badge assignments written
     */
    public function assignBadges(): int
    {
        // Both idempotent, both what a first page load does: the two default
        // badges, then one referent badge per section.
        $this->badgeService->ensureDefaults();
        $this->badgeService->syncSectionReferentBadges();

        $assigned = 0;

        foreach (UnitBlueprint::YEARS as $year) {
            $yearId = $this->yearIds[$year] ?? null;
            if ($yearId === null) {
                continue;
            }

            foreach (UnitBlueprint::sectionsIn($year) as $handle) {
                $sectionId = $this->sectionIds[$handle] ?? null;
                if ($sectionId === null) {
                    continue;
                }

                $staff = $this->staffMemberYearIds($sectionId, $yearId);
                if ($staff === []) {
                    continue;
                }

                foreach (StaffBlueprint::SECTION_BADGES as $index => $badgeName) {
                    $badge = $this->badgeRepository->findByName($badgeName);
                    // A section with a single cadre gets both badges on the
                    // same person rather than losing one of them.
                    $memberYearId = $staff[$index % count($staff)];
                    if ($badge === null || $this->memberBadgeRepository->isAssigned($memberYearId, $badge->id)) {
                        continue;
                    }
                    $this->memberBadgeRepository->assign($memberYearId, $badge->id, $this->actorId);
                    $assigned++;
                }
            }
        }

        foreach (StaffBlueprint::PINNED_BADGES as $pinned) {
            $badge = $this->badgeRepository->findByName($pinned['badge']);
            $memberYearId = $this->memberYearIdOf($pinned['tiers'], $this->yearIds[$pinned['year']] ?? 0);
            if ($badge === null || $memberYearId === null || $this->memberBadgeRepository->isAssigned($memberYearId, $badge->id)) {
                continue;
            }
            $this->memberBadgeRepository->assign($memberYearId, $badge->id, $this->actorId);
            $assigned++;
        }

        return $assigned;
    }

    /**
     * Flags the `Chef de section` function as the trombinoscope's lead, then
     * checks that somebody actually carries it everywhere.
     *
     * @return array{flagged: bool, ledSections: int, headless: list<string>}
     *         `headless` names "<year> / <section>" for every section that
     *         ended up with no responsable at all
     */
    public function flagSectionLead(): array
    {
        $function = $this->functionRepository->findByDeskCode(UnitBlueprint::SECTION_LEAD_FUNCTION);
        if ($function === null) {
            return ['flagged' => false, 'ledSections' => 0, 'headless' => []];
        }

        $functionId = (int) $function['id'];
        (new FunctionFlagsRepository($this->pdo))->setLead($functionId, true);

        $led = 0;
        $headless = [];

        foreach (UnitBlueprint::YEARS as $year) {
            $yearId = $this->yearIds[$year] ?? null;
            if ($yearId === null) {
                continue;
            }

            foreach (UnitBlueprint::sectionsIn($year) as $handle) {
                $sectionId = $this->sectionIds[$handle] ?? null;
                if ($sectionId === null) {
                    continue;
                }
                if ($this->countHoldersOf($functionId, $sectionId, $yearId) > 0) {
                    $led++;
                    continue;
                }
                $headless[] = $year . ' / ' . UnitBlueprint::SECTIONS[$handle]['name'];
            }
        }

        return ['flagged' => true, 'ledSections' => $led, 'headless' => $headless];
    }

    /**
     * The section's cadres for a year, as member_year ids, in a stable order.
     *
     * Deliberately the same shape as Core\Member\SectionService::
     * getSectionStaff()'s own query — chief/admin functions on that section,
     * active member-years only — but returning ids rather than hydrated
     * profiles, since a badge is attached to a member_year and decrypting
     * thirty names to pick two of them would be work for nothing.
     *
     * @return list<int>
     */
    private function staffMemberYearIds(int $sectionId, int $yearId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT mf.member_year_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.section_id = ? AND my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN (\'chief\', \'admin\')
             ORDER BY mf.member_year_id'
        );
        $statement->execute([$sectionId, $yearId]);

        return array_map(
            static fn (array $row): int => (int) $row['member_year_id'],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function countHoldersOf(int $functionId, int $sectionId, int $yearId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS n
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             WHERE mf.function_id = ? AND mf.section_id = ? AND my.scout_year_id = ? AND my.is_active = 1'
        );
        $statement->execute([$functionId, $sectionId, $yearId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['n'];
    }

    private function memberYearIdOf(string $tiers, int $yearId): ?int
    {
        $memberId = $this->memberIds[$tiers] ?? null;
        if ($memberId === null || $yearId === 0) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id FROM member_years WHERE member_id = ? AND scout_year_id = ?');
        $statement->execute([$memberId, $yearId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['id'];
    }
}
