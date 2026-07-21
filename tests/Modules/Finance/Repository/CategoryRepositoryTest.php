<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\TransactionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class CategoryRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CategoryRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new CategoryRepository($this->pdo);
    }

    public function testCreateAssignsIncrementingSortOrder(): void
    {
        $id1 = $this->repository->create('Alimentation');
        $id2 = $this->repository->create('Matériel');

        $categories = $this->repository->findAllOrdered();
        $this->assertSame($id1, $categories[0]->id);
        $this->assertSame($id2, $categories[1]->id);
        $this->assertLessThan($categories[1]->sortOrder, $categories[0]->sortOrder);
    }

    public function testUpdateName(): void
    {
        $id = $this->repository->create('Ancien nom');
        $this->repository->updateName($id, 'Nouveau nom');

        $this->assertSame('Nouveau nom', $this->repository->findById($id)->name);
    }

    public function testSetActive(): void
    {
        $id = $this->repository->create('Catégorie');
        $this->assertTrue($this->repository->findById($id)->isActive);

        $this->repository->setActive($id, false);
        $this->assertFalse($this->repository->findById($id)->isActive);

        $activeOnly = $this->repository->findActiveOrdered();
        $this->assertCount(0, $activeOnly);
    }

    public function testDelete(): void
    {
        $id = $this->repository->create('Catégorie');
        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testIsReferencedByTransactions(): void
    {
        $categoryId = $this->repository->create('Catégorie');
        $this->assertFalse($this->repository->isReferencedByTransactions($categoryId));

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $accountId = $this->createAccount();
        $fiscalYearId = $this->createFiscalYear();
        $transactionRepository->create($accountId, $fiscalYearId, null, '2026-01-15', 'Achat', -20.0, $categoryId, null, 'manual', null);

        $this->assertTrue($this->repository->isReferencedByTransactions($categoryId));
    }

    private function createAccount(): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    private function createFiscalYear(): int
    {
        return FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }
}
