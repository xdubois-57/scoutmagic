<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionMembershipService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class SectionMembershipServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionMembershipRepository $repository;
    private SectionMembershipService $service;
    private int $memberId;
    private int $memberYearId;
    private int $scoutYearId;
    private int $sectionA;
    private int $sectionB;
    private int $functionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SectionMembershipRepository($this->pdo);
        $this->service = new SectionMembershipService($this->repository, new ScoutYearService($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->sectionA = $this->createSection('SEC_A', $branchId);
        $this->sectionB = $this->createSection('SEC_B', $branchId);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $this->scoutYearId, 'John', 'Doe']);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'identified')");
        $this->functionId = (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, int $branchId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id) VALUES (?, ?)');
        $stmt->execute([$deskCode, $branchId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function setMemberFunctionSection(?int $sectionId): void
    {
        $this->pdo->exec('DELETE FROM member_functions WHERE member_year_id = ' . $this->memberYearId);
        if ($sectionId !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
            );
            $stmt->execute([$this->memberYearId, $this->functionId, $sectionId]);
        }
    }

    public function testFirstSyncOpensAPeriodForTheMembersSection(): void
    {
        $this->setMemberFunctionSection($this->sectionA);

        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2025-10-01'));
    }

    public function testReRunningTheSameImportCreatesNoDuplicatePeriod(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->assertCount(1, $this->repository->findAllForMember($this->memberId));
    }

    /**
     * The core scenario the module was built for: a member moves from
     * section A to section B mid-year, across two Desk imports within
     * the SAME scout year.
     */
    public function testMidYearSectionChangeClosesOldPeriodAndOpensNew(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->setMemberFunctionSection($this->sectionB);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2026-03-15'));

        // Section A was active only until the change date.
        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2025-12-01'));
        $this->assertFalse($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2026-04-01'));

        // Section B only from the change date onward.
        $this->assertFalse($this->repository->hasPeriodCovering($this->memberId, $this->sectionB, $this->scoutYearId, '2025-12-01'));
        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionB, $this->scoutYearId, '2026-04-01'));
    }

    public function testRolloverClosesAPeriodLeftOpenFromAnEarlierScoutYearAtThatYearsEndDate(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)");
        $nextYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $nextYearId, 'John', 'Doe']);
        $nextMemberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$nextMemberYearId, $this->functionId, $this->sectionA]);

        $this->service->syncForMember($this->memberId, $nextMemberYearId, $nextYearId, new \DateTimeImmutable('2026-10-01'));

        $periods = $this->repository->findAllForMember($this->memberId);
        $this->assertCount(2, $periods);

        $closed = array_values(array_filter($periods, fn($p) => $p->scoutYearId === $this->scoutYearId));
        $this->assertCount(1, $closed);
        $this->assertSame('2026-08-31', $closed[0]->endDate);
    }

    public function testLeavingASectionEntirelyClosesThePeriodWithNoNewOne(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->setMemberFunctionSection(null);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2026-01-01'));

        $this->assertFalse($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2026-02-01'));
        $this->assertCount(1, $this->repository->findAllForMember($this->memberId));
    }
}
