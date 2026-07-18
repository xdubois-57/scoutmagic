<?php

declare(strict_types=1);

namespace Tests\Core\Badge;

use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberBadgeRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MemberBadgeRepository $repository;
    private BadgeRepository $badgeRepository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new MemberBadgeRepository($this->pdo);
        $this->badgeRepository = new BadgeRepository($this->pdo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function createMemberYear(string $deskId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc']);
        return (int) $this->pdo->lastInsertId();
    }

    public function testAssignThenIsAssignedReturnsTrue(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $badgeId = $this->badgeRepository->create('Infirmier', false);

        $this->repository->assign($memberYearId, $badgeId, null);

        $this->assertTrue($this->repository->isAssigned($memberYearId, $badgeId));
    }

    public function testIsAssignedReturnsFalseWithoutAssignment(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $badgeId = $this->badgeRepository->create('Infirmier', false);

        $this->assertFalse($this->repository->isAssigned($memberYearId, $badgeId));
    }

    public function testUnassignRemovesAssignment(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $badgeId = $this->badgeRepository->create('Infirmier', false);
        $this->repository->assign($memberYearId, $badgeId, null);

        $this->repository->unassign($memberYearId, $badgeId);

        $this->assertFalse($this->repository->isAssigned($memberYearId, $badgeId));
    }

    public function testGetActiveBadgesForMemberYearReturnsAssignedActiveBadges(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $badgeId = $this->badgeRepository->create('Infirmier', false);
        $this->repository->assign($memberYearId, $badgeId, null);

        $badges = $this->repository->getActiveBadgesForMemberYear($memberYearId);

        $this->assertCount(1, $badges);
        $this->assertSame('Infirmier', $badges[0]->name);
    }

    public function testGetActiveBadgesExcludesDeactivatedBadges(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $badgeId = $this->badgeRepository->create('Infirmier', false);
        $this->repository->assign($memberYearId, $badgeId, null);
        $this->badgeRepository->setActive($badgeId, false);

        $badges = $this->repository->getActiveBadgesForMemberYear($memberYearId);

        $this->assertEmpty($badges);
    }

    public function testGetActiveBadgesForMemberYearsBatchesCorrectly(): void
    {
        $memberYearId1 = $this->createMemberYear('D1');
        $memberYearId2 = $this->createMemberYear('D2');
        $badgeId = $this->badgeRepository->create('Infirmier', false);
        $this->repository->assign($memberYearId1, $badgeId, null);

        $result = $this->repository->getActiveBadgesForMemberYears([$memberYearId1, $memberYearId2]);

        $this->assertArrayHasKey($memberYearId1, $result);
        $this->assertArrayNotHasKey($memberYearId2, $result);
    }

    public function testGetActiveBadgesForMemberYearsReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->repository->getActiveBadgesForMemberYears([]));
    }

    public function testBadgeHasAnyAssignmentReflectsAssignmentState(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $badgeId = $this->badgeRepository->create('Infirmier', false);

        $this->assertFalse($this->repository->badgeHasAnyAssignment($badgeId));

        $this->repository->assign($memberYearId, $badgeId, null);
        $this->assertTrue($this->repository->badgeHasAnyAssignment($badgeId));
    }

    public function testAssignedBadgeIdsReturnsOnlyAssignedBadges(): void
    {
        $memberYearId = $this->createMemberYear('D1');
        $assignedBadgeId = $this->badgeRepository->create('Infirmier', false);
        $unassignedBadgeId = $this->badgeRepository->create('Trésorier', false);
        $this->repository->assign($memberYearId, $assignedBadgeId, null);

        $ids = $this->repository->assignedBadgeIds();

        $this->assertSame([$assignedBadgeId], $ids);
        $this->assertNotContains($unassignedBadgeId, $ids);
    }

    public function testAssignedBadgeIdsReturnsEmptyArrayWhenNoAssignments(): void
    {
        $this->badgeRepository->create('Infirmier', false);

        $this->assertSame([], $this->repository->assignedBadgeIds());
    }
}
