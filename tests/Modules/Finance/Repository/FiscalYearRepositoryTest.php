<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Config\ScoutYearService;
use Modules\Finance\Repository\FiscalYearRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * A finance "exercice" is a scout year — this repository is an adapter
 * over Core\Config\ScoutYearService / the shared scout_years table, not
 * an owner of its own data.
 *
 * @group database
 */
class FiscalYearRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ScoutYearService $scoutYearService;
    private FiscalYearRepository $repository;
    private string $currentLabel;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->repository = new FiscalYearRepository($this->pdo, $this->scoutYearService);
        $this->currentLabel = ScoutYearService::labelForDate(new \DateTimeImmutable());
    }

    public function testFindByIdReturnsScoutYear(): void
    {
        $id = FinanceTestHelper::createScoutYear($this->pdo, '2020-2021', '2020-09-01', '2021-08-31');

        $fiscalYear = $this->repository->findById($id);

        $this->assertNotNull($fiscalYear);
        $this->assertSame('2020-2021', $fiscalYear->label);
        $this->assertSame('2020-09-01', $fiscalYear->startDate);
        $this->assertSame('2021-08-31', $fiscalYear->endDate);
    }

    public function testFindByIdReturnsNullWhenUnknown(): void
    {
        $this->assertNull($this->repository->findById(9999));
    }

    public function testFindCurrentIsDateComputed(): void
    {
        $current = $this->repository->findCurrent();

        $this->assertNotNull($current);
        $this->assertSame($this->currentLabel, $current->label);
        $this->assertTrue($current->isCurrent);
    }

    public function testFindByIdMarksOnlyTheDateComputedYearAsCurrent(): void
    {
        $pastId = FinanceTestHelper::createScoutYear($this->pdo, '2020-2021', '2020-09-01', '2021-08-31');

        $past = $this->repository->findById($pastId);
        $this->assertFalse($past->isCurrent);
    }

    public function testFindForDateReturnsContainingFiscalYear(): void
    {
        FinanceTestHelper::createScoutYear($this->pdo, '2020-2021', '2020-09-01', '2021-08-31');

        $found = $this->repository->findForDate('2020-12-15');
        $this->assertNotNull($found);
        $this->assertSame('2020-2021', $found->label);

        $this->assertNull($this->repository->findForDate('2028-01-01'));
    }

    public function testFindForDateNeverCreatesAMissingYear(): void
    {
        $this->assertNull($this->repository->findForDate('1999-01-01'));

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scout_years');
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testFindOldestEndingBefore(): void
    {
        FinanceTestHelper::createScoutYear($this->pdo, '2018-2019', '2018-09-01', '2019-08-31');
        FinanceTestHelper::createScoutYear($this->pdo, '2019-2020', '2019-09-01', '2020-08-31');

        $oldest = $this->repository->findOldestEndingBefore('2021-01-01');

        $this->assertNotNull($oldest);
        $this->assertSame('2018-2019', $oldest->label);
    }

    public function testFindOldestEndingBeforeReturnsNullWhenNoneQualify(): void
    {
        FinanceTestHelper::createScoutYear($this->pdo, '2020-2021', '2020-09-01', '2021-08-31');

        $this->assertNull($this->repository->findOldestEndingBefore('2019-01-01'));
    }

    public function testFindAllOrderedAlwaysIncludesCurrentAndTwoFutureYears(): void
    {
        $all = $this->repository->findAllOrdered();

        $labels = array_map(fn($fy) => $fy->label, $all);
        $this->assertContains($this->currentLabel, $labels);
        $this->assertContains(ScoutYearService::nextLabel($this->currentLabel), $labels);
        $this->assertContains(ScoutYearService::nextLabel(ScoutYearService::nextLabel($this->currentLabel)), $labels);
    }

    public function testFindAllOrderedNeverFabricatesPastYearsThatDontExist(): void
    {
        $all = $this->repository->findAllOrdered();

        // Only current + 2 future are ensured to exist — nothing was
        // seeded for the two years before today, so exactly 3 entries.
        $this->assertCount(3, $all);
    }

    public function testFindAllOrderedIncludesExistingPastYears(): void
    {
        $twoYearsAgoStart = (int) explode('-', $this->currentLabel)[0] - 2;
        $pastLabel = $twoYearsAgoStart . '-' . ($twoYearsAgoStart + 1);
        FinanceTestHelper::createScoutYear($this->pdo, $pastLabel, $twoYearsAgoStart . '-09-01', ($twoYearsAgoStart + 1) . '-08-31');

        $all = $this->repository->findAllOrdered();

        $labels = array_map(fn($fy) => $fy->label, $all);
        $this->assertContains($pastLabel, $labels);
        $this->assertCount(4, $all);
    }

    public function testFindAllOrderedSortedOldestFirst(): void
    {
        $all = $this->repository->findAllOrdered();

        for ($i = 1; $i < count($all); $i++) {
            $this->assertLessThanOrEqual($all[$i]->startDate, $all[$i - 1]->startDate);
        }
    }
}
