<?php

declare(strict_types=1);

namespace Tests\Core\Security\HumanCheck;

use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class HumanCheckRateLimitRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private HumanCheckRateLimitRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new HumanCheckRateLimitRepository($this->pdo);
    }

    public function testCountSinceCountsOnlyMatchingIpAndFormKeyWithinTheWindow(): void
    {
        $this->repository->record('ip-a', 'form-1');
        $this->repository->record('ip-a', 'form-1');
        $this->repository->record('ip-a', 'form-2');
        $this->repository->record('ip-b', 'form-1');

        $this->assertSame(2, $this->repository->countSince('ip-a', 'form-1', '2000-01-01 00:00:00'));
        $this->assertSame(1, $this->repository->countSince('ip-a', 'form-2', '2000-01-01 00:00:00'));
        $this->assertSame(1, $this->repository->countSince('ip-b', 'form-1', '2000-01-01 00:00:00'));
    }

    public function testCountSinceExcludesRowsBeforeTheCutoff(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO human_check_rate_limits (ip_hash, form_key, created_at) VALUES (?, ?, ?)');
        $stmt->execute(['ip-a', 'form-1', '2020-01-01 00:00:00']);

        $this->assertSame(0, $this->repository->countSince('ip-a', 'form-1', '2025-01-01 00:00:00'));
    }

    public function testDeleteOlderThanRemovesOnlyOldRows(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO human_check_rate_limits (ip_hash, form_key, created_at) VALUES (?, ?, ?)');
        $stmt->execute(['ip-a', 'form-1', '2020-01-01 00:00:00']);
        $this->repository->record('ip-b', 'form-1');

        $deleted = $this->repository->deleteOlderThan('2025-01-01 00:00:00');

        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->repository->countSince('ip-a', 'form-1', '2000-01-01 00:00:00'));
        $this->assertSame(1, $this->repository->countSince('ip-b', 'form-1', '2000-01-01 00:00:00'));
    }
}
