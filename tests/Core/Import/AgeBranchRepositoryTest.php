<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\AgeBranchRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class AgeBranchRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AgeBranchRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new AgeBranchRepository($this->pdo);
    }

    public function testNewBranchHasNoLogoAndTheDefaultExplanationUrl(): void
    {
        $id = $this->repo->create('LOU01', 'Louveteaux');

        $branch = $this->repo->findById($id);

        $this->assertNotNull($branch);
        $this->assertNull($branch['logo_file_id']);
        $this->assertSame('https://lesscouts.be/fr/site-parents/le-parcours-scout', $branch['explanation_url']);
    }

    public function testSetLogoUpdatesTheFileId(): void
    {
        $id = $this->repo->create('LOU01', 'Louveteaux');
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a.png', 'a.png', 'image/png', 10, 'public')");
        $fileId = (int) $this->pdo->lastInsertId();

        $this->repo->setLogo($id, $fileId);

        $branch = $this->repo->findById($id);
        $this->assertSame($fileId, $branch['logo_file_id']);
    }

    public function testSetExplanationUrlOverridesTheDefault(): void
    {
        $id = $this->repo->create('LOU01', 'Louveteaux');

        $this->repo->setExplanationUrl($id, 'https://example.test/louveteaux');

        $branch = $this->repo->findById($id);
        $this->assertSame('https://example.test/louveteaux', $branch['explanation_url']);
    }

    public function testFindAllOrderedIncludesLogoAndUrlFields(): void
    {
        $this->repo->create('LOU01', 'Louveteaux');

        $branches = $this->repo->findAllOrdered();

        $this->assertArrayHasKey('logo_file_id', $branches[0]);
        $this->assertArrayHasKey('explanation_url', $branches[0]);
    }

    public function testFindByDeskCodeIncludesLogoAndUrlFields(): void
    {
        $this->repo->create('LOU01', 'Louveteaux');

        $branch = $this->repo->findByDeskCode('LOU01');

        $this->assertNotNull($branch);
        $this->assertArrayHasKey('logo_file_id', $branch);
        $this->assertArrayHasKey('explanation_url', $branch);
    }

    /**
     * @dataProvider defaultLogoFilenameProvider
     */
    public function testDefaultLogoFilename(int $sortOrder, ?string $expected): void
    {
        $this->assertSame($expected, AgeBranchRepository::defaultLogoFilename($sortOrder));
    }

    /**
     * @return array<string, array{int, ?string}>
     */
    public static function defaultLogoFilenameProvider(): array
    {
        return [
            'Baladins' => [10, 'logo_baladins.png'],
            'Louveteaux' => [20, 'logo_louveteaux.png'],
            'Eclaireurs' => [30, 'logo_eclaireurs.png'],
            'Pionniers' => [40, 'logo_pionniers.png'],
            'Staff d\'U' => [50, 'logo_staffdu.png'],
            'Route' => [60, 'logo_route.png'],
            'Iama' => [70, 'logo_iama.png'],
            'Unknown has no default' => [99, null],
        ];
    }
}
