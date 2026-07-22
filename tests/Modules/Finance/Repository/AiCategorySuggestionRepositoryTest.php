<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\AiCategorySuggestionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class AiCategorySuggestionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AiCategorySuggestionRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new AiCategorySuggestionRepository($this->pdo);
    }

    public function testFindRecentEmptyInitially(): void
    {
        $this->assertSame([], $this->repository->findRecent());
    }

    public function testFindRecentReturnsMostRecentFirst(): void
    {
        $this->repository->create('Sapins de Noël');
        $this->repository->create('Frais bancaires');

        $this->assertSame(['Frais bancaires', 'Sapins de Noël'], $this->repository->findRecent());
    }

    public function testFindRecentCapsAtTenOldestPruned(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->repository->create("Suggestion {$i}");
        }

        $recent = $this->repository->findRecent();

        $this->assertCount(10, $recent);
        $this->assertSame('Suggestion 12', $recent[0]);
        $this->assertNotContains('Suggestion 1', $recent);
        $this->assertNotContains('Suggestion 2', $recent);
    }
}
