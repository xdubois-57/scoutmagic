<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Repository;

use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class CalendarUnitFeedTokenRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalendarUnitFeedTokenRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->repository = new CalendarUnitFeedTokenRepository($this->pdo);
    }

    public function testFindTokenReturnsNullWhenNoneExists(): void
    {
        $this->assertNull($this->repository->findToken());
    }

    public function testSetTokenCreatesRowWhenNoneExists(): void
    {
        $this->repository->setToken('tok-1');

        $this->assertSame('tok-1', $this->repository->findToken());
    }

    public function testSetTokenReplacesTheSingleRow(): void
    {
        $this->repository->setToken('tok-1');
        $this->repository->setToken('tok-2');

        $this->assertSame('tok-2', $this->repository->findToken());
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM calendar_unit_feed_token');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testTokenExists(): void
    {
        $this->repository->setToken('tok-1');

        $this->assertTrue($this->repository->tokenExists('tok-1'));
        $this->assertFalse($this->repository->tokenExists('nope'));
    }
}
