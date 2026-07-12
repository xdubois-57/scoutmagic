<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\ScoutYearService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
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

    public function testEnsureYearIsIdempotent(): void
    {
        $id1 = $this->service->ensureYear('2024-2025');
        $id2 = $this->service->ensureYear('2024-2025');

        $this->assertSame($id1, $id2);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scout_years');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testGetAllReturnsOrderedByStartDateDesc(): void
    {
        $this->service->ensureYear('2023-2024');
        $this->service->ensureYear('2025-2026');
        $this->service->ensureYear('2024-2025');

        $all = $this->service->getAll();

        $this->assertCount(3, $all);
        $this->assertSame('2025-2026', $all[0]['label']);
        $this->assertSame('2024-2025', $all[1]['label']);
        $this->assertSame('2023-2024', $all[2]['label']);
    }
}
