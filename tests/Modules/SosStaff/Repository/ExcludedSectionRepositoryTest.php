<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Repository;

use Modules\SosStaff\Repository\ExcludedSectionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class ExcludedSectionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ExcludedSectionRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->repo = new ExcludedSectionRepository($this->pdo);
    }

    private function createSection(string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $deskCode, 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $deskCode]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testFindAllReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->repo->findAll());
    }

    public function testReplaceAllStoresGivenSections(): void
    {
        $sectionA = $this->createSection('ROU01');
        $sectionB = $this->createSection('PIO01');

        $this->repo->replaceAll([$sectionA, $sectionB]);

        $this->assertEqualsCanonicalizing([$sectionA, $sectionB], $this->repo->findAll());
    }

    public function testReplaceAllRemovesSectionsNoLongerIncluded(): void
    {
        $sectionA = $this->createSection('ROU01');
        $sectionB = $this->createSection('PIO01');
        $this->repo->replaceAll([$sectionA, $sectionB]);

        $this->repo->replaceAll([$sectionA]);

        $this->assertSame([$sectionA], $this->repo->findAll());
    }

    public function testReplaceAllWithEmptyArrayClearsEverything(): void
    {
        $sectionA = $this->createSection('ROU01');
        $this->repo->replaceAll([$sectionA]);

        $this->repo->replaceAll([]);

        $this->assertSame([], $this->repo->findAll());
    }

    public function testReplaceAllDeduplicatesSectionIds(): void
    {
        $sectionA = $this->createSection('ROU01');

        $this->repo->replaceAll([$sectionA, $sectionA]);

        $this->assertSame([$sectionA], $this->repo->findAll());
    }
}
