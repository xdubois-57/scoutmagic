<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Repository;

use Core\Database\Connection;
use Core\Import\AgeBranchRepository;
use Core\Security\EncryptionService;
use Modules\Leadership\Repository\LeadershipRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[Group('database')]
class LeadershipRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private LeadershipRepository $repository;

    private int $previousYearId;
    private int $currentYearId;
    private int $louveteauxId;
    private int $pionniersId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new LeadershipRepository(Connection::withPdo($this->pdo), $this->encryption);

        $this->previousYearId = $this->insertScoutYear('2024-2025', '2024-09-01', '2025-08-31');
        $this->currentYearId = $this->insertScoutYear('2025-2026', '2025-09-01', '2026-08-31');

        $this->louveteauxId = $this->insertSection('LOUV', 'Louveteaux', 'Louveteaux');
        $this->pionniersId = $this->insertSection('PION', 'Pionniers', 'Pionniers');
    }

    public function testFindStaffFunctionsDecryptsIdentityAndKeepsOneRowPerFunction(): void
    {
        $memberId = $this->insertMember('D1');
        $memberYearId = $this->insertMemberYear($memberId, $this->currentYearId, [
            'first_name' => 'Camille',
            'last_name' => 'Dupont',
            'totem' => 'Akéla',
            'birth_date' => '2004-05-12',
            'formation_level' => 'T2',
        ]);

        $animator = $this->insertFunction('ANIM', 'Animateur', 'chief');
        $steward = $this->insertFunction('INTE', 'Intendant', 'intendant');
        $this->insertMemberFunction($memberYearId, $animator, $this->louveteauxId, '2025-09-01', true);
        $this->insertMemberFunction($memberYearId, $steward, null, null, false);

        $rows = $this->repository->findStaffFunctions($this->currentYearId);

        $this->assertCount(2, $rows);
        $this->assertSame('Camille', $rows[0]->firstName);
        $this->assertSame('Dupont', $rows[0]->lastName);
        $this->assertSame('Akéla', $rows[0]->totem);
        $this->assertSame('2004-05-12', $rows[0]->birthDate);
        $this->assertSame('T2', $rows[0]->formationLevel);
        $this->assertSame('Louveteaux', $rows[0]->sectionName);
        $this->assertSame('2025-09-01', $rows[0]->functionStartDate);
        $this->assertTrue($rows[0]->isAnimation());
        $this->assertTrue($rows[1]->isSteward());
    }

    public function testFindStaffFunctionsExcludesAnimesAndInactiveMembers(): void
    {
        $animeFunction = $this->insertFunction('ANIME', 'Louveteau', 'identified');
        $animator = $this->insertFunction('ANIM', 'Animateur', 'chief');

        $anime = $this->insertMemberYear($this->insertMember('D1'), $this->currentYearId, ['first_name' => 'Enfant']);
        $this->insertMemberFunction($anime, $animeFunction, $this->louveteauxId);

        $inactive = $this->insertMemberYear(
            $this->insertMember('D2'),
            $this->currentYearId,
            ['first_name' => 'Parti', 'is_active' => 0]
        );
        $this->insertMemberFunction($inactive, $animator, $this->louveteauxId);

        $this->assertSame([], $this->repository->findStaffFunctions($this->currentYearId));
    }

    public function testASectionWithNoConfiguredNameFallsBackToItsDeskCode(): void
    {
        $unnamed = $this->insertSection('XYZ', null, 'Louveteaux');
        $memberYearId = $this->insertMemberYear($this->insertMember('D1'), $this->currentYearId, []);
        $this->insertMemberFunction($memberYearId, $this->insertFunction('ANIM', 'Animateur', 'chief'), $unnamed);

        $rows = $this->repository->findStaffFunctions($this->currentYearId);

        $this->assertSame('XYZ', $rows[0]->sectionName);
    }

    public function testCountAnimesBySectionCountsChildrenOnceAndExcludesEveryStaffRole(): void
    {
        $animeFunction = $this->insertFunction('ANIME', 'Louveteau', 'identified');
        $animator = $this->insertFunction('ANIM', 'Animateur', 'chief');
        $steward = $this->insertFunction('INTE', 'Intendant', 'intendant');

        // Two children, one of them holding two function rows in the same
        // section (the double-count trap).
        $childA = $this->insertMemberYear($this->insertMember('C1'), $this->currentYearId, []);
        $this->insertMemberFunction($childA, $animeFunction, $this->louveteauxId);
        $this->insertMemberFunction($childA, $animeFunction, $this->louveteauxId);
        $childB = $this->insertMemberYear($this->insertMember('C2'), $this->currentYearId, []);
        $this->insertMemberFunction($childB, $animeFunction, $this->louveteauxId);

        // Staff of the same section carry the same section_id — only the
        // role filter tells them apart.
        $staff = $this->insertMemberYear($this->insertMember('S1'), $this->currentYearId, []);
        $this->insertMemberFunction($staff, $animator, $this->louveteauxId);
        $this->insertMemberFunction($staff, $steward, $this->louveteauxId);

        $this->assertSame([$this->louveteauxId => 2], $this->repository->countAnimesBySection($this->currentYearId));
    }

    public function testFindMemberIdsWithAnimationFunctionIsScopedToItsYear(): void
    {
        $animator = $this->insertFunction('ANIM', 'Animateur', 'chief');
        $steward = $this->insertFunction('INTE', 'Intendant', 'intendant');

        $veteran = $this->insertMember('V1');
        $this->insertMemberFunction(
            $this->insertMemberYear($veteran, $this->previousYearId, []),
            $animator,
            $this->louveteauxId
        );

        $stewardOnly = $this->insertMember('S1');
        $this->insertMemberFunction(
            $this->insertMemberYear($stewardOnly, $this->previousYearId, []),
            $steward,
            null
        );

        $newcomer = $this->insertMember('N1');
        $this->insertMemberFunction(
            $this->insertMemberYear($newcomer, $this->currentYearId, []),
            $animator,
            $this->louveteauxId
        );

        $previous = $this->repository->findMemberIdsWithAnimationFunction($this->previousYearId);

        $this->assertSame([$veteran], $previous, 'An intendance function is not animation.');
    }

    /**
     * By start_date, never by id — a back-filled past year gets a higher id
     * than the years that follow it, and ordering by id would then name the
     * wrong "previous year".
     */
    public function testFindPreviousScoutYearUsesStartDateNotInsertionOrder(): void
    {
        $backfilled = $this->insertScoutYear('2023-2024', '2023-09-01', '2024-08-31');

        $this->assertSame($this->previousYearId, $this->repository->findPreviousScoutYearId($this->currentYearId));
        $this->assertSame($backfilled, $this->repository->findPreviousScoutYearId($this->previousYearId));
        $this->assertNull($this->repository->findPreviousScoutYearId($backfilled));
    }

    public function testFindMembersInBranchSectionsRespectsThePeriodWindow(): void
    {
        $pionnierSortOrder = AgeBranchRepository::canonicalSortOrder('Pionniers');

        $inside = $this->insertMember('P1');
        $this->insertMemberYear($inside, $this->currentYearId, ['first_name' => 'Dedans']);
        $this->insertPeriod($inside, $this->pionniersId, $this->currentYearId, '2025-09-01', null);

        $closedBefore = $this->insertMember('P2');
        $this->insertMemberYear($closedBefore, $this->currentYearId, ['first_name' => 'Parti']);
        $this->insertPeriod($closedBefore, $this->pionniersId, $this->currentYearId, '2025-09-01', '2025-08-15');

        $otherBranch = $this->insertMember('L1');
        $this->insertMemberYear($otherBranch, $this->currentYearId, ['first_name' => 'Ailleurs']);
        $this->insertPeriod($otherBranch, $this->louveteauxId, $this->currentYearId, '2025-09-01', null);

        $rows = $this->repository->findMembersInBranchSections(
            $this->currentYearId,
            $pionnierSortOrder,
            '2025-09-01'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Dedans', $rows[0]['first_name']);
        $this->assertSame('Pionniers', $rows[0]['section_name']);
    }

    public function testFindEarliestSectionPeriodStartTakesTheOldestOfTheYear(): void
    {
        $memberId = $this->insertMember('M1');
        $this->insertPeriod($memberId, $this->louveteauxId, $this->currentYearId, '2025-11-04', null);
        $this->insertPeriod($memberId, $this->pionniersId, $this->currentYearId, '2025-09-08', '2025-11-03');
        $this->insertPeriod($memberId, $this->louveteauxId, $this->previousYearId, '2024-09-02', null);

        $this->assertSame(
            '2025-09-08',
            $this->repository->findEarliestSectionPeriodStart($memberId, $this->currentYearId)
        );
        $this->assertNull($this->repository->findEarliestSectionPeriodStart(999, $this->currentYearId));
    }

    public function testFindEarliestSectionPeriodStartsAnswersForAWholeListAtOnce(): void
    {
        // The stewards page asked once per line, so a unit with a dozen
        // intendants issued a dozen round trips to render one table.
        $first = $this->insertMember('M1');
        $second = $this->insertMember('M2');
        $withoutPeriod = $this->insertMember('M3');

        $this->insertPeriod($first, $this->louveteauxId, $this->currentYearId, '2025-11-04', null);
        $this->insertPeriod($first, $this->pionniersId, $this->currentYearId, '2025-09-08', '2025-11-03');
        $this->insertPeriod($second, $this->pionniersId, $this->currentYearId, '2025-10-01', null);
        $this->insertPeriod($second, $this->louveteauxId, $this->previousYearId, '2024-09-02', null);

        $starts = $this->repository->findEarliestSectionPeriodStarts(
            [$first, $second, $withoutPeriod, 999],
            $this->currentYearId
        );

        $this->assertSame(
            [$first => '2025-09-08', $second => '2025-10-01'],
            $starts,
            'Same answer as the single-member version, for everybody at once.'
        );
    }

    public function testFindEarliestSectionPeriodStartsWithNobodyAsksNothing(): void
    {
        $this->assertSame([], $this->repository->findEarliestSectionPeriodStarts([], $this->currentYearId));
    }

    public function testFindLastImportAtTakesTheMostRecentOfTheYear(): void
    {
        $this->assertNull($this->repository->findLastImportAt($this->currentYearId));

        $this->insertImport($this->currentYearId, '2025-09-10 08:00:00');
        $this->insertImport($this->currentYearId, '2025-10-02 19:30:00');
        $this->insertImport($this->previousYearId, '2026-01-01 00:00:00');

        $this->assertSame('2025-10-02 19:30:00', $this->repository->findLastImportAt($this->currentYearId));
    }

    public function testCountFormationLevelsCountsStaffHoldersPerRawValue(): void
    {
        $animator = $this->insertFunction('ANIM', 'Animateur', 'chief');
        $animeFunction = $this->insertFunction('ANIME', 'Louveteau', 'identified');

        foreach (['T2', 'T2', 'Brevet', null, ''] as $index => $level) {
            $memberYearId = $this->insertMemberYear(
                $this->insertMember('S' . $index),
                $this->currentYearId,
                ['formation_level' => $level]
            );
            $this->insertMemberFunction($memberYearId, $animator, $this->louveteauxId);
        }

        // An animé's level never enters the staff counts.
        $child = $this->insertMemberYear($this->insertMember('C1'), $this->currentYearId, ['formation_level' => 'T3']);
        $this->insertMemberFunction($child, $animeFunction, $this->louveteauxId);

        $counts = $this->repository->countFormationLevels($this->currentYearId);
        ksort($counts);

        $this->assertSame(['Brevet' => 1, 'T2' => 2], $counts);
    }

    public function testFindFormationLevelForMemberIsScopedToTheYear(): void
    {
        $memberId = $this->insertMember('M1');
        $this->insertMemberYear($memberId, $this->previousYearId, ['formation_level' => 'T1']);
        $this->insertMemberYear($memberId, $this->currentYearId, ['formation_level' => 'T3']);

        $this->assertSame('T3', $this->repository->findFormationLevelForMember($memberId, $this->currentYearId));
        $this->assertSame('T1', $this->repository->findFormationLevelForMember($memberId, $this->previousYearId));
    }

    public function testFindFormationLevelForMemberTreatsAnEmptyColumnAsAbsent(): void
    {
        $memberId = $this->insertMember('M1');
        $this->insertMemberYear($memberId, $this->currentYearId, ['formation_level' => '']);

        $this->assertNull($this->repository->findFormationLevelForMember($memberId, $this->currentYearId));
    }

    /**
     * A ciphertext this installation's key cannot open — a restored backup,
     * a rotated key — must read as an absent field, not take the page down.
     */
    public function testUndecryptableIdentityReadsAsAbsentRatherThanThrowing(): void
    {
        $memberYearId = $this->insertMemberYear($this->insertMember('M1'), $this->currentYearId, []);
        $this->pdo->prepare('UPDATE member_years SET totem_encrypted = ? WHERE id = ?')
            ->execute(['pas un chiffré valide', $memberYearId]);
        $this->insertMemberFunction($memberYearId, $this->insertFunction('ANIM', 'Animateur', 'chief'), null);

        $rows = $this->repository->findStaffFunctions($this->currentYearId);

        $this->assertNull($rows[0]->totem);
    }

    // --- fixtures -------------------------------------------------------

    private function insertScoutYear(string $label, string $start, string $end): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Branches are shared: two sections of the same branch reuse its row. */
    private function insertSection(string $deskCode, ?string $name, string $branchLabel): int
    {
        $find = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $find->execute([$branchLabel]);
        $branchId = $find->fetchColumn();

        if ($branchId === false) {
            $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
            $stmt->execute([$branchLabel, $branchLabel, AgeBranchRepository::canonicalSortOrder($branchLabel)]);
            $branchId = $this->pdo->lastInsertId();
        }

        $branchId = (int) $branchId;

        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, $deskCode, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertMember(string $deskId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function insertMemberYear(int $memberId, int $scoutYearId, array $fields): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted,
                 birth_date_encrypted, formation_level, scout_year_offset, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt((string) ($fields['first_name'] ?? 'Prénom'), 'member_years.first_name'),
            $this->encryption->encrypt((string) ($fields['last_name'] ?? 'Nom'), 'member_years.last_name'),
            isset($fields['totem'])
                ? $this->encryption->encrypt((string) $fields['totem'], 'member_years.totem')
                : null,
            isset($fields['birth_date'])
                ? $this->encryption->encrypt((string) $fields['birth_date'], 'member_years.birth_date')
                : null,
            $fields['formation_level'] ?? null,
            $fields['scout_year_offset'] ?? 0,
            $fields['is_active'] ?? 1,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertFunction(string $deskCode, string $label, string $role): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $label, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertMemberFunction(
        int $memberYearId,
        int $functionId,
        ?int $sectionId,
        ?string $startDate = null,
        bool $isMain = false
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, start_date, is_main_function)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $startDate, $isMain ? 1 : 0]);
    }

    private function insertPeriod(int $memberId, int $sectionId, int $scoutYearId, string $start, ?string $end): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $sectionId, $scoutYearId, $start, $end]);
    }

    private function insertImport(int $scoutYearId, string $importedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, line_count, member_count, imported_at) VALUES (?, 1, 1, ?)'
        );
        $stmt->execute([$scoutYearId, $importedAt]);
    }
}
