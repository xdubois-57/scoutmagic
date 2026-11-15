<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Repository;

use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class OnCallRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private OnCallRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->repo = new OnCallRepository($this->pdo);
    }

    public function testFindForRangeReturnsEmptyArrayWhenNothingAssigned(): void
    {
        $this->assertSame([], $this->repo->findForRange('2026-07-01', '2026-07-31'));
    }

    public function testReplaceRangeInsertsAssignments(): void
    {
        $assignments = [
            new OnCallAssignment(1, '2026-07-05', OnCallAssignment::STATE_ONCALL),
            new OnCallAssignment(2, '2026-07-06', OnCallAssignment::STATE_UNAVAILABLE),
        ];

        $this->repo->replaceRange('2026-07-01', '2026-07-31', $assignments);

        $found = $this->repo->findForRange('2026-07-01', '2026-07-31');
        $this->assertCount(2, $found);
        $this->assertSame(1, $found[0]->memberId);
        $this->assertSame('2026-07-05', $found[0]->date);
        $this->assertSame(OnCallAssignment::STATE_ONCALL, $found[0]->state);
    }

    public function testReplaceRangeRemovesPreviousAssignmentsInThatRange(): void
    {
        $this->repo->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(1, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->repo->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(2, '2026-07-10', OnCallAssignment::STATE_ONCALL),
        ]);

        $found = $this->repo->findForRange('2026-07-01', '2026-07-31');
        $this->assertCount(1, $found);
        $this->assertSame(2, $found[0]->memberId);
    }

    public function testReplaceRangeDoesNotTouchAssignmentsOutsideTheRange(): void
    {
        $this->repo->replaceRange('2026-06-01', '2026-06-30', [
            new OnCallAssignment(1, '2026-06-30', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->repo->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(2, '2026-07-01', OnCallAssignment::STATE_ONCALL),
        ]);

        $june = $this->repo->findForRange('2026-06-01', '2026-06-30');
        $this->assertCount(1, $june);
        $this->assertSame(1, $june[0]->memberId);
    }

    public function testFindForDateReturnsOnlyThatDaysAssignments(): void
    {
        $this->repo->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(1, '2026-07-05', OnCallAssignment::STATE_ONCALL),
            new OnCallAssignment(2, '2026-07-06', OnCallAssignment::STATE_ONCALL),
        ]);

        $found = $this->repo->findForDate('2026-07-05');

        $this->assertCount(1, $found);
        $this->assertSame(1, $found[0]->memberId);
    }

    public function testFindAllReturnsEveryStoredAssignment(): void
    {
        $this->repo->replaceRange('2026-06-01', '2026-06-30', [
            new OnCallAssignment(1, '2026-06-15', OnCallAssignment::STATE_ONCALL),
        ]);
        $this->repo->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(2, '2026-07-15', OnCallAssignment::STATE_UNAVAILABLE),
        ]);

        $all = $this->repo->findAll();

        $this->assertCount(2, $all);
    }

    public function testDeleteOlderThanRemovesOnlyOldRowsAndReturnsCount(): void
    {
        $this->repo->replaceRange('2024-01-01', '2024-01-31', [
            new OnCallAssignment(1, '2024-01-15', OnCallAssignment::STATE_ONCALL),
        ]);
        $this->repo->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(2, '2026-07-15', OnCallAssignment::STATE_ONCALL),
        ]);

        $deleted = $this->repo->deleteOlderThan('2025-01-01');

        $this->assertSame(1, $deleted);
        $this->assertSame([], $this->repo->findForRange('2024-01-01', '2024-01-31'));
        $this->assertCount(1, $this->repo->findForRange('2026-07-01', '2026-07-31'));
    }

    /**
     * A month saved with the same member twice on one day breaks the
     * (member, day) unique index halfway through. The save is all or
     * nothing: the month keeps the assignments it had, the error reaches
     * the caller, and no transaction is left open behind it.
     */
    public function testAMonthThatBreaksTheOneRowPerDayRuleKeepsTheMonthItHad(): void
    {
        $this->repo->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(1, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);

        try {
            $this->repo->replaceRange('2026-07-01', '2026-07-31', [
                new OnCallAssignment(2, '2026-07-10', OnCallAssignment::STATE_ONCALL),
                new OnCallAssignment(2, '2026-07-10', OnCallAssignment::STATE_UNAVAILABLE),
            ]);
            $this->fail('A duplicate (member, day) must reach the caller.');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $found = $this->repo->findForRange('2026-07-01', '2026-07-31');
        $this->assertCount(1, $found);
        $this->assertSame(1, $found[0]->memberId);
        $this->assertSame('2026-07-05', $found[0]->date);
    }
}
