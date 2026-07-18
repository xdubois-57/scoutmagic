<?php

declare(strict_types=1);

namespace Tests\Core\Badge;

use Core\Badge\BadgeRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class BadgeRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private BadgeRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new BadgeRepository($this->pdo);
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create('Infirmier', true);

        $badge = $this->repository->findById($id);

        $this->assertNotNull($badge);
        $this->assertSame('Infirmier', $badge->name);
        $this->assertTrue($badge->isDefault);
        $this->assertTrue($badge->isActive);
    }

    public function testFindByIdReturnsNullForUnknown(): void
    {
        $this->assertNull($this->repository->findById(9999));
    }

    public function testFindByNameReturnsMatchingBadge(): void
    {
        $this->repository->create('Trésorier', false);

        $badge = $this->repository->findByName('Trésorier');

        $this->assertNotNull($badge);
        $this->assertSame('Trésorier', $badge->name);
    }

    public function testFindByNameReturnsNullWhenNoMatch(): void
    {
        $this->assertNull($this->repository->findByName('Inconnu'));
    }

    public function testFindAllOrdersByName(): void
    {
        $this->repository->create('Trésorier', false);
        $this->repository->create('Communication', false);

        $names = array_map(fn($b) => $b->name, $this->repository->findAll());

        $this->assertSame(['Communication', 'Trésorier'], $names);
    }

    public function testUpdateChangesName(): void
    {
        $id = $this->repository->create('Old', false);

        $this->repository->update($id, 'New');

        $badge = $this->repository->findById($id);
        $this->assertSame('New', $badge->name);
    }

    public function testSetActiveTogglesFlag(): void
    {
        $id = $this->repository->create('Infirmier', true);

        $this->repository->setActive($id, false);
        $this->assertFalse($this->repository->findById($id)->isActive);

        $this->repository->setActive($id, true);
        $this->assertTrue($this->repository->findById($id)->isActive);
    }

    public function testDeleteRemovesBadge(): void
    {
        $id = $this->repository->create('Temp', false);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }
}
