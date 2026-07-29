<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Repository;

use Modules\Retro\Repository\RateLimitRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
class RateLimitRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RateLimitRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $this->repository = new RateLimitRepository($this->pdo);
    }

    public function testRecordAndCountSince(): void
    {
        $this->repository->record('hash1', 'comment');
        $this->repository->record('hash1', 'comment');
        $this->repository->record('hash1', 'vote');

        $since = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->assertSame(2, $this->repository->countSince('hash1', 'comment', $since));
        $this->assertSame(1, $this->repository->countSince('hash1', 'vote', $since));
    }

    public function testCountSinceIgnoresOtherIdentifiers(): void
    {
        $this->repository->record('hash1', 'comment');
        $this->repository->record('hash2', 'comment');

        $since = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->assertSame(1, $this->repository->countSince('hash1', 'comment', $since));
    }

    public function testDeleteOlderThanRemovesOnlyOldRows(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO retro_rate_limits (identifier_hash, action_type, created_at) VALUES (?, ?, ?)');
        $stmt->execute(['old', 'comment', '2020-01-01 00:00:00']);
        $this->repository->record('recent', 'comment');

        $deleted = $this->repository->deleteOlderThan((new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'));

        $this->assertSame(1, $deleted);
        $since = '2000-01-01 00:00:00';
        $this->assertSame(0, $this->repository->countSince('old', 'comment', $since));
        $this->assertSame(1, $this->repository->countSince('recent', 'comment', $since));
    }
}
