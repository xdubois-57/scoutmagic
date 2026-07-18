<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\SectionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class SectionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SectionRepository $repository;
    private int $branchId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SectionRepository($this->pdo);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 10)");
        $this->branchId = (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, ?string $name, bool $visible = true): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deskCode, $this->branchId, $name, $visible ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testExcludesHiddenSections(): void
    {
        $this->createSection('BAL01', 'Renards', true);
        $this->createSection('BAL02', 'Ecureuils', false);

        $groups = $this->repository->findAllGroupedByBranch();

        $this->assertCount(1, $groups);
        $names = array_column($groups[0]['sections'], 'name');
        $this->assertContains('Renards', $names);
        $this->assertNotContains('Ecureuils', $names);
    }

    public function testGroupIsOmittedWhenAllSectionsInItAreHidden(): void
    {
        $this->createSection('BAL01', 'Renards', false);

        $groups = $this->repository->findAllGroupedByBranch();

        $this->assertEmpty($groups);
    }

    public function testExcludesInactiveSections(): void
    {
        $activeId = $this->createSection('BAL01', 'Renards', true);
        $inactiveId = $this->createSection('BAL02', 'Ecureuils', true);
        $this->pdo->exec("UPDATE sections SET is_active = 0 WHERE id = {$inactiveId}");
        // Sanity: the active one stays untouched.
        $this->pdo->exec("UPDATE sections SET is_active = 1 WHERE id = {$activeId}");

        $groups = $this->repository->findAllGroupedByBranch();

        $names = array_column($groups[0]['sections'], 'name');
        $this->assertContains('Renards', $names);
        $this->assertNotContains('Ecureuils', $names);
    }
}
