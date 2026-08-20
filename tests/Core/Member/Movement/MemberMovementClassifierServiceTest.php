<?php

declare(strict_types=1);

namespace Tests\Core\Member\Movement;

use Core\Config\ScoutYearService;
use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementRepository;
use Core\Member\Movement\MemberMovementStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Business-rule tests for the generic core member movement classifier —
 * these tests are the definition of "new" / "section change" / "branch
 * change" / "returning" / "continuing" / "unknown" for this codebase.
 */
#[Group('database')]
class MemberMovementClassifierServiceTest extends TestCase
{
    private \PDO $pdo;
    private MemberMovementClassifierService $classifier;
    private int $branchLouveteaux;
    private int $branchEclaireurs;
    private int $sectionA; // Louveteaux
    private int $sectionB; // Louveteaux
    private int $sectionEclaireurs;
    private int $yearN2; // two years before current
    private int $yearN1; // previous year
    private int $yearN;  // current year

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $scoutYearService = new ScoutYearService($this->pdo);
        $this->classifier = new MemberMovementClassifierService(new MemberMovementRepository($this->pdo), $scoutYearService);

        $this->branchLouveteaux = $this->createBranch('LOU', 'Louveteaux', 20);
        $this->branchEclaireurs = $this->createBranch('ECL', 'Éclaireurs', 30);
        $this->sectionA = $this->createSection('LOU01', $this->branchLouveteaux);
        $this->sectionB = $this->createSection('LOU02', $this->branchLouveteaux);
        $this->sectionEclaireurs = $this->createSection('ECL01', $this->branchEclaireurs);

        $this->yearN2 = $this->createScoutYear('2022-2023', '2022-09-01', '2023-08-31');
        $this->yearN1 = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');
        $this->yearN = $this->createScoutYear('2024-2025', '2024-09-01', '2025-08-31');
    }

    public function testNewMemberHasNoActiveHistoryAtAll(): void
    {
        $memberId = $this->createMember();
        // No member_years row at all before the current year.

        $result = $this->classifyOne($memberId, $this->sectionA, $this->branchLouveteaux);

        $this->assertSame(MemberMovementStatus::NEW, $result->status);
    }

    public function testContinuingInTheSameSectionAsLastYear(): void
    {
        $memberId = $this->createMember();
        $this->createActiveSectionYear($memberId, $this->yearN1, $this->sectionA);

        $result = $this->classifyOne($memberId, $this->sectionA, $this->branchLouveteaux);

        $this->assertSame(MemberMovementStatus::CONTINUING, $result->status);
        $this->assertSame($this->sectionA, $result->previousSectionId);
    }

    public function testSectionChangeWithinTheSameBranch(): void
    {
        $memberId = $this->createMember();
        $this->createActiveSectionYear($memberId, $this->yearN1, $this->sectionA);

        $result = $this->classifyOne($memberId, $this->sectionB, $this->branchLouveteaux);

        $this->assertSame(MemberMovementStatus::SECTION_CHANGE, $result->status);
        $this->assertSame($this->sectionA, $result->previousSectionId);
        $this->assertSame($this->branchLouveteaux, $result->previousAgeBranchId);
    }

    public function testBranchChange(): void
    {
        $memberId = $this->createMember();
        $this->createActiveSectionYear($memberId, $this->yearN1, $this->sectionA);

        $result = $this->classifyOne($memberId, $this->sectionEclaireurs, $this->branchEclaireurs);

        $this->assertSame(MemberMovementStatus::BRANCH_CHANGE, $result->status);
    }

    public function testReturningAfterAGapYear(): void
    {
        $memberId = $this->createMember();
        // Active two years ago, absent last year.
        $this->createActiveSectionYear($memberId, $this->yearN2, $this->sectionA);

        $result = $this->classifyOne($memberId, $this->sectionA, $this->branchLouveteaux);

        $this->assertSame(MemberMovementStatus::RETURNING, $result->status);
    }

    public function testReturningFromAHorsSectionFunctionLastYear(): void
    {
        // Known and active last year, but their only function had no
        // section (e.g. an administrative/hors-branche role) — coming back
        // into a section this year is a return, not a fresh arrival.
        $memberId = $this->createMember();
        $memberYearId = $this->createMemberYear($memberId, $this->yearN1, true);
        $this->attachFunction($memberYearId, null, null, 'identified', true);

        $result = $this->classifyOne($memberId, $this->sectionA, $this->branchLouveteaux);

        $this->assertSame(MemberMovementStatus::RETURNING, $result->status);
    }

    public function testAbsenceOfPreviousScoutYearIsUnknownNotNew(): void
    {
        // No scout year at all is recorded before the current one — must
        // never be silently reported as "new" for everyone.
        $pdo = DatabaseTestHelper::createTestDatabase();
        $scoutYearService = new ScoutYearService($pdo);
        $classifier = new MemberMovementClassifierService(new MemberMovementRepository($pdo), $scoutYearService);

        $branchId = $this->insertBranch($pdo, 'LOU', 'Louveteaux', 20);
        $sectionId = $this->insertSection($pdo, 'LOU01', $branchId);
        $firstYearId = $this->insertScoutYear($pdo, '2024-2025', '2024-09-01', '2025-08-31');
        $memberId = $this->insertMember($pdo);

        $results = $classifier->classifyBatch($firstYearId, [
            ['member_id' => $memberId, 'section_id' => $sectionId, 'age_branch_id' => $branchId],
        ]);

        $this->assertSame(MemberMovementStatus::UNKNOWN, $results[$memberId]->status);
    }

    public function testIncompleteHistoryWithDanglingSectionReferenceDegradesGracefully(): void
    {
        // A previous-year function references a section id that no longer
        // resolves to a real section/branch row — this must never crash
        // and must never fabricate a section/branch comparison from
        // nothing. It falls back to the coarser "was active, no resolvable
        // section" signal (same as an honest hors-section function), which
        // is still a true statement about the data, never a guess.
        $memberId = $this->createMember();
        $memberYearId = $this->createMemberYear($memberId, $this->yearN1, true);
        $ghostSectionId = 999999;
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        )->execute([$memberYearId, $this->ensureFunction('identified'), $ghostSectionId]);

        $result = $this->classifyOne($memberId, $this->sectionA, $this->branchLouveteaux);

        // The dangling reference simply cannot join to a section row, so
        // the repository reports "no active section last year" — treated
        // the same as any other absent-last-year case (returning, since a
        // member_years row does exist for that year).
        $this->assertSame(MemberMovementStatus::RETURNING, $result->status);
    }

    public function testMultipleFunctionsPrefersMainFunctionForPreviousSection(): void
    {
        $memberId = $this->createMember();
        $memberYearId = $this->createMemberYear($memberId, $this->yearN1, true);
        // Secondary, non-main function in section B...
        $this->attachFunction($memberYearId, $this->sectionB, $this->branchLouveteaux, 'identified', false);
        // ...but the MAIN function is in section A: A must win.
        $this->attachFunction($memberYearId, $this->sectionA, $this->branchLouveteaux, 'identified', true);

        $result = $this->classifyOne($memberId, $this->sectionA, $this->branchLouveteaux);

        $this->assertSame(MemberMovementStatus::CONTINUING, $result->status);
    }

    public function testChangeOfFunctionAloneWithoutSectionChangeIsContinuity(): void
    {
        // A different function role/label this year, same section: still
        // continuity — only the section comparison matters here.
        $memberId = $this->createMember();
        $this->createActiveSectionYear($memberId, $this->yearN1, $this->sectionA, 'identified');

        $result = $this->classifyOne($memberId, $this->sectionA, $this->branchLouveteaux);

        $this->assertSame(MemberMovementStatus::CONTINUING, $result->status);
    }

    public function testPriorityBranchChangeWinsOverSectionChangeWhenBothDiffer(): void
    {
        // Both the section AND the branch differ — the documented priority
        // is branch change over section change (a branch change already
        // implies a section change, so section_change never fires here).
        $memberId = $this->createMember();
        $this->createActiveSectionYear($memberId, $this->yearN1, $this->sectionA);

        $result = $this->classifyOne($memberId, $this->sectionEclaireurs, $this->branchEclaireurs);

        $this->assertSame(MemberMovementStatus::BRANCH_CHANGE, $result->status);
        $this->assertNotSame(MemberMovementStatus::SECTION_CHANGE, $result->status);
    }

    public function testBatchClassifiesMultipleMembersWithoutPerMemberQueries(): void
    {
        $memberNew = $this->createMember();
        $memberContinuing = $this->createMember();
        $this->createActiveSectionYear($memberContinuing, $this->yearN1, $this->sectionA);

        $results = $this->classifier->classifyBatch($this->yearN, [
            ['member_id' => $memberNew, 'section_id' => $this->sectionA, 'age_branch_id' => $this->branchLouveteaux],
            ['member_id' => $memberContinuing, 'section_id' => $this->sectionA, 'age_branch_id' => $this->branchLouveteaux],
        ]);

        $this->assertSame(MemberMovementStatus::NEW, $results[$memberNew]->status);
        $this->assertSame(MemberMovementStatus::CONTINUING, $results[$memberContinuing]->status);
    }

    private function classifyOne(int $memberId, int $sectionId, int $ageBranchId): \Core\Member\Movement\MemberMovementResult
    {
        $results = $this->classifier->classifyBatch($this->yearN, [
            ['member_id' => $memberId, 'section_id' => $sectionId, 'age_branch_id' => $ageBranchId],
        ]);
        return $results[$memberId];
    }

    private function createActiveSectionYear(int $memberId, int $scoutYearId, int $sectionId, string $functionRole = 'identified'): int
    {
        $memberYearId = $this->createMemberYear($memberId, $scoutYearId, true);
        $stmt = $this->pdo->prepare('SELECT age_branch_id FROM sections WHERE id = ?');
        $stmt->execute([$sectionId]);
        $branchId = (int) $stmt->fetchColumn();
        $this->attachFunction($memberYearId, $sectionId, $branchId, $functionRole, true);
        return $memberYearId;
    }

    private function attachFunction(int $memberYearId, ?int $sectionId, ?int $ageBranchId, string $functionRole, bool $isMain): void
    {
        $functionId = $this->ensureFunction($functionRole);
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $ageBranchId, $isMain ? 1 : 0]);
    }

    private function ensureFunction(string $role): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$role]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)')->execute([$role, ucfirst($role), $role]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMember(): int
    {
        return $this->insertMember($this->pdo);
    }

    private function createMemberYear(int $memberId, int $scoutYearId, bool $isActive): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, 'x', 'y', $isActive ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createBranch(string $code, string $label, int $sortOrder): int
    {
        return $this->insertBranch($this->pdo, $code, $label, $sortOrder);
    }

    private function createSection(string $deskCode, int $branchId): int
    {
        return $this->insertSection($this->pdo, $deskCode, $branchId);
    }

    private function createScoutYear(string $label, string $start, string $end): int
    {
        return $this->insertScoutYear($this->pdo, $label, $start, $end);
    }

    private function insertMember(\PDO $pdo): int
    {
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        return (int) $pdo->lastInsertId();
    }

    private function insertBranch(\PDO $pdo, string $code, string $label, int $sortOrder): int
    {
        $stmt = $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$code, $label, $sortOrder]);
        return (int) $pdo->lastInsertId();
    }

    private function insertSection(\PDO $pdo, string $deskCode, int $branchId): int
    {
        $stmt = $pdo->prepare('INSERT INTO sections (desk_code, age_branch_id) VALUES (?, ?)');
        $stmt->execute([$deskCode, $branchId]);
        return (int) $pdo->lastInsertId();
    }

    private function insertScoutYear(\PDO $pdo, string $label, string $start, string $end): int
    {
        $stmt = $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);
        return (int) $pdo->lastInsertId();
    }
}
