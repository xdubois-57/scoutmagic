<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Repository;

use Modules\SosStaff\Repository\CalendarSyncRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class CalendarSyncRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalendarSyncRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->repo = new CalendarSyncRepository($this->pdo);
    }

    public function testCreateAndFindAll(): void
    {
        $id = $this->repo->create(1, '2026-07-05', '2026-07-12', 42);

        $all = $this->repo->findAll();
        $this->assertCount(1, $all);
        $this->assertSame($id, $all[0]->id);
        $this->assertSame(1, $all[0]->memberId);
        $this->assertSame('2026-07-05', $all[0]->startDate);
        $this->assertSame('2026-07-12', $all[0]->endDate);
        $this->assertSame(42, $all[0]->calendarEventId);
    }

    public function testCreateAllowsNullCalendarEventId(): void
    {
        $this->repo->create(1, '2026-07-05', '2026-07-12', null);

        $all = $this->repo->findAll();
        $this->assertNull($all[0]->calendarEventId);
    }

    public function testDeleteAllClearsEveryRow(): void
    {
        $this->repo->create(1, '2026-07-05', '2026-07-12', 42);
        $this->repo->create(2, '2026-07-06', '2026-07-08', 43);

        $this->repo->deleteAll();

        $this->assertSame([], $this->repo->findAll());
    }

    public function testFindOlderThanReturnsOnlyStreaksEndingBeforeCutoff(): void
    {
        $this->repo->create(1, '2024-01-01', '2024-01-05', 1);
        $this->repo->create(2, '2026-07-01', '2026-07-05', 2);

        $old = $this->repo->findOlderThan('2025-01-01');

        $this->assertCount(1, $old);
        $this->assertSame(1, $old[0]->memberId);
    }

    public function testDeleteByIdsRemovesOnlySpecifiedRows(): void
    {
        $idA = $this->repo->create(1, '2026-07-05', '2026-07-12', 42);
        $idB = $this->repo->create(2, '2026-07-06', '2026-07-08', 43);

        $this->repo->deleteByIds([$idA]);

        $remaining = $this->repo->findAll();
        $this->assertCount(1, $remaining);
        $this->assertSame($idB, $remaining[0]->id);
    }

    public function testDeleteByIdsWithEmptyArrayIsNoOp(): void
    {
        $this->repo->create(1, '2026-07-05', '2026-07-12', 42);

        $this->repo->deleteByIds([]);

        $this->assertCount(1, $this->repo->findAll());
    }
}
