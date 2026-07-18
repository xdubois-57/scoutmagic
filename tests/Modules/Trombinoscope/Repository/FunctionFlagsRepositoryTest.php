<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Repository;

use Modules\Trombinoscope\Repository\FunctionFlagsRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class FunctionFlagsRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private FunctionFlagsRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo->exec('CREATE TABLE trombinoscope_function_flags (
            function_id INTEGER PRIMARY KEY,
            is_lead INTEGER NOT NULL DEFAULT 0
        )');
        $this->repository = new FunctionFlagsRepository($this->pdo);
    }

    private function createFunction(string $code, string $role): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)');
        $stmt->execute([$code, $code, $role]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testUnconfiguredChiefFunctionDefaultsToNotLead(): void
    {
        $id = $this->createFunction('ANIM', 'chief');

        $flags = $this->repository->getLeadFlags();

        $this->assertFalse($flags[$id]);
    }

    public function testNonStaffFunctionIsExcluded(): void
    {
        $this->createFunction('SCOUT', 'identified');

        $flags = $this->repository->getLeadFlags();

        $this->assertEmpty($flags);
    }

    public function testSetLeadOverridesDefault(): void
    {
        $id = $this->createFunction('ANIM', 'chief');

        $this->repository->setLead($id, true);

        $flags = $this->repository->getLeadFlags();
        $this->assertTrue($flags[$id]);
    }

    public function testSetLeadCanBeCalledTwice(): void
    {
        $id = $this->createFunction('ANIM', 'chief');

        $this->repository->setLead($id, true);
        $this->repository->setLead($id, false);

        $flags = $this->repository->getLeadFlags();
        $this->assertFalse($flags[$id]);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM trombinoscope_function_flags')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
