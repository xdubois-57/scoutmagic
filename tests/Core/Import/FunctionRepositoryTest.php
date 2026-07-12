<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\FunctionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class FunctionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private FunctionRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new FunctionRepository($this->pdo);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testFindByIdReturnsFunction(): void
    {
        $id = $this->repo->create('Animateur', 'Animateur', 'chief', true);
        $result = $this->repo->findById($id);

        $this->assertNotNull($result);
        $this->assertSame($id, $result['id']);
        $this->assertSame('Animateur', $result['desk_code']);
        $this->assertSame('chief', $result['role']);
        $this->assertTrue($result['confirmed']);
    }

    public function testFindUnconfirmedReturnsOnlyUnconfirmed(): void
    {
        $this->repo->create('Animé', 'Animé', 'identified', false);
        $this->repo->create('Animateur', 'Animateur', 'chief', true);
        $this->repo->create('Scout', 'Scout', 'identified', false);

        $unconfirmed = $this->repo->findUnconfirmed();

        $this->assertCount(2, $unconfirmed);
        $codes = array_map(fn(array $f) => $f['desk_code'], $unconfirmed);
        $this->assertContains('Animé', $codes);
        $this->assertContains('Scout', $codes);
    }

    public function testFindAllGroupedByRoleGroupsCorrectly(): void
    {
        $this->repo->create('Animé', 'Animé', 'identified', true);
        $this->repo->create('Animateur', 'Animateur', 'chief', true);
        $this->repo->create('Intendant', 'Intendant', 'intendant', true);
        $this->repo->create('Chef', 'Chef', 'chief', true);
        // Unconfirmed should NOT appear in grouped results
        $this->repo->create('Scout', 'Scout', 'identified', false);

        $grouped = $this->repo->findAllGroupedByRole();

        $this->assertArrayHasKey('identified', $grouped);
        $this->assertArrayHasKey('chief', $grouped);
        $this->assertArrayHasKey('intendant', $grouped);

        $this->assertCount(1, $grouped['identified']);
        $this->assertCount(2, $grouped['chief']);
        $this->assertCount(1, $grouped['intendant']);

        // Unconfirmed 'Scout' should not be in any group
        foreach ($grouped as $functions) {
            foreach ($functions as $f) {
                $this->assertNotSame('Scout', $f['desk_code']);
            }
        }
    }

    public function testFindAllGroupedByRoleReturnsEmptyWhenNoConfirmed(): void
    {
        $this->repo->create('Scout', 'Scout', 'identified', false);
        $grouped = $this->repo->findAllGroupedByRole();
        $this->assertEmpty($grouped);
    }

    public function testUpdateRoleUpdatesRoleAndConfirmed(): void
    {
        $id = $this->repo->create('Animé', 'Animé', 'identified', false);

        $this->repo->updateRole($id, 'chief', true);

        $updated = $this->repo->findById($id);
        $this->assertNotNull($updated);
        $this->assertSame('chief', $updated['role']);
        $this->assertTrue($updated['confirmed']);
    }

    public function testUpdateRoleKeepsConfirmedFalseWhenRequested(): void
    {
        $id = $this->repo->create('Animé', 'Animé', 'identified', true);

        $this->repo->updateRole($id, 'intendant', false);

        $updated = $this->repo->findById($id);
        $this->assertNotNull($updated);
        $this->assertSame('intendant', $updated['role']);
        $this->assertFalse($updated['confirmed']);
    }
}
