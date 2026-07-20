<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\FiscalYearRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class FiscalYearRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private FiscalYearRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new FiscalYearRepository($this->pdo);
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create('2026-2027', '2026-09-01', '2027-08-31');

        $fiscalYear = $this->repository->findById($id);

        $this->assertNotNull($fiscalYear);
        $this->assertSame('2026-2027', $fiscalYear->label);
        $this->assertSame('2026-09-01', $fiscalYear->startDate);
        $this->assertSame('2027-08-31', $fiscalYear->endDate);
        $this->assertFalse($fiscalYear->isCurrent);
    }

    public function testFindByIdReturnsNullWhenUnknown(): void
    {
        $this->assertNull($this->repository->findById(9999));
    }

    public function testUniqueLabelConstraint(): void
    {
        $this->repository->create('2026-2027', '2026-09-01', '2027-08-31');

        $this->expectException(\PDOException::class);
        $this->repository->create('2026-2027', '2027-09-01', '2028-08-31');
    }

    public function testSetCurrentClearsPreviousFlag(): void
    {
        $id1 = $this->repository->create('2025-2026', '2025-09-01', '2026-08-31');
        $id2 = $this->repository->create('2026-2027', '2026-09-01', '2027-08-31');

        $this->repository->setCurrent($id1);
        $this->assertTrue($this->repository->findById($id1)->isCurrent);

        $this->repository->setCurrent($id2);
        $this->assertFalse($this->repository->findById($id1)->isCurrent);
        $this->assertTrue($this->repository->findById($id2)->isCurrent);
        $this->assertSame($id2, $this->repository->findCurrent()->id);
    }

    public function testFindForDateReturnsContainingFiscalYear(): void
    {
        $this->repository->create('2026-2027', '2026-09-01', '2027-08-31');

        $found = $this->repository->findForDate('2026-12-15');
        $this->assertNotNull($found);
        $this->assertSame('2026-2027', $found->label);

        $this->assertNull($this->repository->findForDate('2028-01-01'));
    }

    public function testFindAllOrderedByStartDateDescending(): void
    {
        $this->repository->create('2025-2026', '2025-09-01', '2026-08-31');
        $this->repository->create('2026-2027', '2026-09-01', '2027-08-31');

        $all = $this->repository->findAllOrdered();
        $this->assertCount(2, $all);
        $this->assertSame('2026-2027', $all[0]->label);
    }
}
