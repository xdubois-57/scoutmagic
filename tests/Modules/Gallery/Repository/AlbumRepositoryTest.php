<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Repository;

use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AlbumRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $repository;
    private int $authorId;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->repository = new AlbumRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'MEUTE_A', 'Meute A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create(Album::TYPE_LOCAL, 'Camp d\'été', 'Semaine 1', '2026-07-01', $this->sectionId, $this->scoutYearId, null, null, $this->authorId);

        $album = $this->repository->findById($id);

        $this->assertNotNull($album);
        $this->assertSame('Camp d\'été', $album->title);
        $this->assertSame('Semaine 1', $album->subtitle);
        $this->assertSame($this->sectionId, $album->sectionId);
        $this->assertTrue($album->isLocal());
        $this->assertNull($album->coverMediaId);
    }

    public function testCreateExternalAlbum(): void
    {
        $id = $this->repository->create(Album::TYPE_EXTERNAL, 'Album partagé', null, '2026-06-01', null, $this->scoutYearId, 'https://example.com/album', null, $this->authorId);

        $album = $this->repository->findById($id);

        $this->assertFalse($album->isLocal());
        $this->assertSame('https://example.com/album', $album->externalUrl);
        $this->assertNull($album->sectionId);
    }

    public function testUpdateChangesFields(): void
    {
        $id = $this->repository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', $this->sectionId, $this->scoutYearId, null, null, $this->authorId);

        $this->repository->update($id, 'Nouveau titre', 'Sous-titre', '2026-02-01', null, null);

        $album = $this->repository->findById($id);
        $this->assertSame('Nouveau titre', $album->title);
        $this->assertSame('Sous-titre', $album->subtitle);
        $this->assertSame('2026-02-01', $album->albumDate);
        $this->assertNull($album->sectionId);
    }

    public function testUpdateOgMetadata(): void
    {
        $id = $this->repository->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);

        $this->repository->updateOgMetadata($id, 'OG Title', 'OG Description', 'https://example.com/image.jpg', null);

        $album = $this->repository->findById($id);
        $this->assertSame('OG Title', $album->ogTitle);
        $this->assertSame('OG Description', $album->ogDescription);
        $this->assertSame('https://example.com/image.jpg', $album->ogImageUrl);
        $this->assertNull($album->ogImageFileId);
    }

    public function testUpdateOgMetadataStoresTheCachedImageFileId(): void
    {
        $id = $this->repository->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();

        $this->repository->updateOgMetadata($id, 'OG Title', 'OG Description', 'https://example.com/image.jpg', $fileId);

        $album = $this->repository->findById($id);
        $this->assertSame($fileId, $album->ogImageFileId);
    }

    public function testSetCoverMediaId(): void
    {
        $id = $this->repository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId);

        $this->repository->setCoverMediaId($id, 42);

        $this->assertSame(42, $this->repository->findById($id)->coverMediaId);
    }

    public function testDeleteRemovesAlbum(): void
    {
        $id = $this->repository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testFindVisibleFiltersBySectionAndYear(): void
    {
        $sectionAlbumId = $this->repository->create(Album::TYPE_LOCAL, 'Section album', null, '2026-01-01', $this->sectionId, $this->scoutYearId, null, null, $this->authorId);
        $unitWideId = $this->repository->create(Album::TYPE_LOCAL, 'Unit-wide album', null, '2026-01-02', null, $this->scoutYearId, null, null, $this->authorId);

        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([1, 'OTHER', 'Autre section']);
        $otherSectionId = (int) $this->pdo->lastInsertId();
        $this->repository->create(Album::TYPE_LOCAL, 'Other section album', null, '2026-01-03', $otherSectionId, $this->scoutYearId, null, null, $this->authorId);

        $visible = $this->repository->findVisible([$this->sectionId], [$this->scoutYearId]);
        $visibleIds = array_map(fn(Album $a) => $a->id, $visible);

        $this->assertContains($sectionAlbumId, $visibleIds);
        $this->assertContains($unitWideId, $visibleIds);
        $this->assertCount(2, $visible);
    }

    public function testFindVisibleReturnsUnitWideAlbumsEvenWithNoLinkedSections(): void
    {
        $unitWideId = $this->repository->create(Album::TYPE_LOCAL, 'Unit-wide', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId);
        $this->repository->create(Album::TYPE_LOCAL, 'Section-only', null, '2026-01-01', $this->sectionId, $this->scoutYearId, null, null, $this->authorId);

        $visible = $this->repository->findVisible([], [$this->scoutYearId]);

        $this->assertCount(1, $visible);
        $this->assertSame($unitWideId, $visible[0]->id);
    }

    public function testFindVisibleReturnsEmptyWhenNoScoutYears(): void
    {
        $this->repository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId);

        $this->assertSame([], $this->repository->findVisible([], []));
    }
}
