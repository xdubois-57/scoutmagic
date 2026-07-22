<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\CategoryRepository;
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
        $id = $this->repository->create($this->categoryId, 0, 'Delhaize', null, null);

        $rule = $this->repository->findById($id);
        $this->assertNotNull($rule);
        $this->assertSame($this->categoryId, $rule->categoryId);
        $this->assertSame('Delhaize', $rule->keywordPattern);
        $this->assertNull($rule->counterpartyAccountPattern);
        $this->assertNull($rule->amountRange);
        $this->assertTrue($rule->isActive);
        $this->assertFalse($rule->isSystem);
        $this->assertFalse($rule->isDefault);
    }

    public function testCreateWithAllThreeConditionsAtOnce(): void
    {
        $id = $this->repository->create($this->categoryId, 0, 'delhaize', 'BE71096123456769', '10-50');

        $rule = $this->repository->findById($id);
        $this->assertSame('delhaize', $rule->keywordPattern);
        $this->assertSame('BE71096123456769', $rule->counterpartyAccountPattern);
        $this->assertSame('10-50', $rule->amountRange);
    }

    public function testCreateSystemAndDefaultFlags(): void
    {
        $systemId = $this->repository->create($this->categoryId, 0, null, 'BE71096123456769', null, isSystem: true);
        $defaultId = $this->repository->create($this->categoryId, 1, 'camp', null, null, isSystem: false, isDefault: true);

        $this->assertTrue($this->repository->findById($systemId)->isSystem);
        $this->assertFalse($this->repository->findById($systemId)->isDefault);
        $this->assertTrue($this->repository->findById($defaultId)->isDefault);
        $this->assertFalse($this->repository->findById($defaultId)->isSystem);
    }

    public function testFindAllOrderedByPriority(): void
    {
        $id1 = $this->repository->create($this->categoryId, 5, 'B', null, null);
        $id2 = $this->repository->create($this->categoryId, 1, 'A', null, null);

        $all = $this->repository->findAllOrderedByPriority();
        $this->assertSame($id2, $all[0]->id);
        $this->assertSame($id1, $all[1]->id);
    }

    public function testUpdate(): void
    {
        $id = $this->repository->create($this->categoryId, 0, 'Old', null, null);

        $this->repository->update($id, $this->categoryId, null, null, '>100');

        $rule = $this->repository->findById($id);
        $this->assertNull($rule->keywordPattern);
        $this->assertSame('>100', $rule->amountRange);
    }

    public function testSetActive(): void
    {
        $id = $this->repository->create($this->categoryId, 0, 'A', null, null);

        $this->repository->setActive($id, false);
        $this->assertFalse($this->repository->findById($id)->isActive);
        $this->assertCount(0, $this->repository->findActiveOrderedByPriority());
    }

    public function testDelete(): void
    {
        $id = $this->repository->create($this->categoryId, 0, 'A', null, null);
        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testDeleteAllForCategory(): void
    {
        $this->repository->create($this->categoryId, 0, 'A', null, null);
        $this->repository->create($this->categoryId, 1, 'B', null, null);
        $otherCategoryId = (new CategoryRepository($this->pdo))->create('Autre');
        $unrelatedId = $this->repository->create($otherCategoryId, 0, 'C', null, null);

        $this->repository->deleteAllForCategory($this->categoryId);

        $this->assertCount(0, array_filter($this->repository->findAllOrderedByPriority(), fn($r) => $r->categoryId === $this->categoryId));
        $this->assertNotNull($this->repository->findById($unrelatedId));
    }

    public function testFindSystemRuleForCategory(): void
    {
        $this->repository->create($this->categoryId, 0, 'not-system', null, null);
        $systemId = $this->repository->create($this->categoryId, 1, null, 'BE71096123456769', null, isSystem: true);

        $rule = $this->repository->findSystemRuleForCategory($this->categoryId);

        $this->assertNotNull($rule);
        $this->assertSame($systemId, $rule->id);
    }

    public function testFindSystemRuleForCategoryReturnsNullWhenNone(): void
    {
        $this->repository->create($this->categoryId, 0, 'A', null, null);

        $this->assertNull($this->repository->findSystemRuleForCategory($this->categoryId));
    }

    public function testDeleteAllDefaultOnlyTouchesDefaultRules(): void
    {
        $customId = $this->repository->create($this->categoryId, 0, 'custom', null, null);
        $systemId = $this->repository->create($this->categoryId, 1, null, 'BE71096123456769', null, isSystem: true);
        $defaultId = $this->repository->create($this->categoryId, 2, 'camp', null, null, isSystem: false, isDefault: true);

        $this->repository->deleteAllDefault();

        $this->assertNotNull($this->repository->findById($customId));
        $this->assertNotNull($this->repository->findById($systemId));
        $this->assertNull($this->repository->findById($defaultId));
    }

    public function testReorderPersistsNewPriorities(): void
    {
        $id1 = $this->repository->create($this->categoryId, 0, 'A', null, null);
        $id2 = $this->repository->create($this->categoryId, 1, 'B', null, null);

        $this->repository->reorder([$id2, $id1]);

        $all = $this->repository->findAllOrderedByPriority();
        $this->assertSame($id2, $all[0]->id);
        $this->assertSame($id1, $all[1]->id);
    }
}
