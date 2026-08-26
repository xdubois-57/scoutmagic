<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\ScoutYearService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearServiceTest extends TestCase
{
    private \PDO $pdo;
    private ScoutYearService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new ScoutYearService($this->pdo);
    }

    public function testGetCurrentYearCreatesYearIfNoneExists(): void
    {
        $year = $this->service->getCurrentYear();

        $this->assertNotEmpty($year['label']);
        $this->assertGreaterThan(0, $year['id']);

        // Verify it was persisted
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scout_years');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * The four fields this service returns are fixed at INSERT and never
     * updated, so repeats within one request are answered from memory —
     * proven by deleting the rows behind the service's back.
     */
    public function testGetCurrentYearAndFindByIdAreMemoized(): void
    {
        $year = $this->service->getCurrentYear();
        $this->assertNotNull($this->service->findById($year['id']));

        $this->pdo->exec('DELETE FROM scout_years');

        $this->assertSame($year, $this->service->getCurrentYear());
        $this->assertSame($year, $this->service->findById($year['id']));
        // …and no ghost row was recreated by the memoized getCurrentYear().
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scout_years');
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testFindByIdMemoizesAMissWithoutHidingLaterYears(): void
    {
        $this->assertNull($this->service->findById(424242));

        $year = $this->service->getCurrentYear();
        $this->assertNotNull($this->service->findById($year['id']));
    }

    public function testLabelForDateSeptemberStartsNewYear(): void
    {
        $date = new \DateTimeImmutable('2025-09-15');
        $this->assertSame('2025-2026', ScoutYearService::labelForDate($date));
    }

    public function testLabelForDateAugustBelongsToPreviousYear(): void
    {
        $date = new \DateTimeImmutable('2026-08-15');
        $this->assertSame('2025-2026', ScoutYearService::labelForDate($date));
    }

    public function testLabelForDateJanuaryBelongsToPreviousYear(): void
    {
        $date = new \DateTimeImmutable('2026-01-10');
        $this->assertSame('2025-2026', ScoutYearService::labelForDate($date));
    }

    public function testLabelForDateDecemberBelongsToCurrentYear(): void
    {
        $date = new \DateTimeImmutable('2025-12-01');
        $this->assertSame('2025-2026', ScoutYearService::labelForDate($date));
    }

    public function testNextLabelComputesFollowingYear(): void
    {
        $this->assertSame('2026-2027', ScoutYearService::nextLabel('2025-2026'));
        $this->assertSame('2001-2002', ScoutYearService::nextLabel('2000-2001'));
    }

    public function testEnsureYearIsIdempotent(): void
    {
        $id1 = $this->service->ensureYear('2024-2025');
        $id2 = $this->service->ensureYear('2024-2025');

        $this->assertSame($id1, $id2);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scout_years');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testGetAllReturnsOrderedByStartDateAsc(): void
    {
        $this->service->ensureYear('2023-2024');
        $this->service->ensureYear('2025-2026');
        $this->service->ensureYear('2024-2025');

        $all = $this->service->getAll();

        $this->assertCount(3, $all);
        $this->assertSame('2023-2024', $all[0]['label']);
        $this->assertSame('2024-2025', $all[1]['label']);
        $this->assertSame('2025-2026', $all[2]['label']);
    }
}
