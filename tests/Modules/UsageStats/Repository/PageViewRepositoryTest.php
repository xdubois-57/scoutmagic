<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats\Repository;

use Modules\UsageStats\Repository\PageViewRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\UsageStats\UsageStatsTestHelper;

class PageViewRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PageViewRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        UsageStatsTestHelper::createTables($this->pdo);
        $this->repository = new PageViewRepository($this->pdo);
    }

    public function testTheFirstViewCreatesTheRowAndTheNextOnesIncrementIt(): void
    {
        $this->repository->increment('2026-08', '/calendar', 'calendar', 'identified');
        $this->repository->increment('2026-08', '/calendar', 'calendar', 'identified');
        $this->repository->increment('2026-08', '/calendar', 'calendar', 'identified');

        $this->assertSame(1, $this->rowCount());
        $this->assertSame(3, $this->countFor('2026-08', '/calendar', 'identified'));
    }

    /**
     * The three dimensions of the unique key, one at a time: a different
     * month, a different page or a different audience is a different
     * counter, and never an increment of somebody else's.
     */
    public function testEachOfTheThreeDimensionsSplitsTheCounter(): void
    {
        $this->repository->increment('2026-08', '/calendar', 'calendar', 'identified');
        $this->repository->increment('2026-09', '/calendar', 'calendar', 'identified');
        $this->repository->increment('2026-08', '/news', 'news', 'identified');
        $this->repository->increment('2026-08', '/calendar', 'calendar', 'anonymous');

        $this->assertSame(4, $this->rowCount());
        $this->assertSame(1, $this->countFor('2026-08', '/calendar', 'identified'));
    }

    /**
     * A route that changes hands keeps ONE history: the counter carries on
     * and the module column tells the truth about where the page lives now.
     */
    public function testAConflictRefreshesTheModuleWithoutSplittingTheHistory(): void
    {
        $this->repository->increment('2026-08', '/trombinoscope', 'core', 'identified');
        $this->repository->increment('2026-08', '/trombinoscope', 'trombinoscope', 'identified');

        $this->assertSame(1, $this->rowCount());
        $this->assertSame(2, $this->countFor('2026-08', '/trombinoscope', 'identified'));

        $stmt = $this->pdo->query('SELECT module_id FROM usage_page_views');
        $this->assertNotFalse($stmt);
        $this->assertSame('trombinoscope', $stmt->fetchColumn());
    }

    public function testRetentionDeletesEveryMonthStrictlyBeforeTheCutoff(): void
    {
        $this->repository->increment('2023-12', '/calendar', 'calendar', 'identified');
        $this->repository->increment('2024-08', '/calendar', 'calendar', 'identified');
        $this->repository->increment('2024-09', '/calendar', 'calendar', 'identified');
        $this->repository->increment('2026-08', '/calendar', 'calendar', 'identified');

        $this->assertSame(2, $this->repository->deleteMonthsBefore('2024-09'));

        $stmt = $this->pdo->query('SELECT month FROM usage_page_views ORDER BY month');
        $this->assertNotFalse($stmt);
        $this->assertSame(['2024-09', '2026-08'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM usage_page_views');
        $this->assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function countFor(string $month, string $routePattern, string $audience): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT view_count FROM usage_page_views WHERE month = ? AND route_pattern = ? AND audience = ?'
        );
        $stmt->execute([$month, $routePattern, $audience]);

        return (int) $stmt->fetchColumn();
    }
}
