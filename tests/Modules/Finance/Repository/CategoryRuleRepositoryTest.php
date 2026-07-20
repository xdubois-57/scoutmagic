<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\CategoryRuleRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class CategoryRuleRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CategoryRuleRepository $repository;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new CategoryRuleRepository($this->pdo);
        $this->categoryId = (new CategoryRepository($this->pdo))->create('Alimentation');
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create($this->categoryId, 0, CategoryRule::CONDITION_KEYWORD, 'Delhaize');

        $rule = $this->repository->findById($id);
        $this->assertNotNull($rule);
        $this->assertSame($this->categoryId, $rule->categoryId);
        $this->assertSame(CategoryRule::CONDITION_KEYWORD, $rule->conditionType);
        $this->assertSame('Delhaize', $rule->conditionValue);
        $this->assertTrue($rule->isActive);
    }

    public function testFindAllOrderedByPriority(): void
    {
        $id1 = $this->repository->create($this->categoryId, 5, CategoryRule::CONDITION_KEYWORD, 'B');
        $id2 = $this->repository->create($this->categoryId, 1, CategoryRule::CONDITION_KEYWORD, 'A');

        $all = $this->repository->findAllOrderedByPriority();
        $this->assertSame($id2, $all[0]->id);
        $this->assertSame($id1, $all[1]->id);
    }

    public function testUpdate(): void
    {
        $id = $this->repository->create($this->categoryId, 0, CategoryRule::CONDITION_KEYWORD, 'Old');

        $this->repository->update($id, $this->categoryId, CategoryRule::CONDITION_AMOUNT_RANGE, '>100');

        $rule = $this->repository->findById($id);
        $this->assertSame(CategoryRule::CONDITION_AMOUNT_RANGE, $rule->conditionType);
        $this->assertSame('>100', $rule->conditionValue);
    }

    public function testSetActive(): void
    {
        $id = $this->repository->create($this->categoryId, 0, CategoryRule::CONDITION_KEYWORD, 'A');

        $this->repository->setActive($id, false);
        $this->assertFalse($this->repository->findById($id)->isActive);
        $this->assertCount(0, $this->repository->findActiveOrderedByPriority());
    }

    public function testDelete(): void
    {
        $id = $this->repository->create($this->categoryId, 0, CategoryRule::CONDITION_KEYWORD, 'A');
        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testReorderPersistsNewPriorities(): void
    {
        $id1 = $this->repository->create($this->categoryId, 0, CategoryRule::CONDITION_KEYWORD, 'A');
        $id2 = $this->repository->create($this->categoryId, 1, CategoryRule::CONDITION_KEYWORD, 'B');

        $this->repository->reorder([$id2, $id1]);

        $all = $this->repository->findAllOrderedByPriority();
        $this->assertSame($id2, $all[0]->id);
        $this->assertSame($id1, $all[1]->id);
    }
}
