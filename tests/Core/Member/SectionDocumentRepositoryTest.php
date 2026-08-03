<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\SectionDocument;
use Core\Member\SectionDocumentRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class SectionDocumentRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SectionDocumentRepository $repository;
    private int $sectionId;
    private int $scoutYearId;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SectionDocumentRepository($this->pdo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('SEC_A', {$branchId})");
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, encrypted) VALUES ('a/b.pdf.enc', 'a.pdf', 'application/pdf', 1000, 'public', 1)");
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAssignsIncrementingSortOrder(): void
    {
        $first = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'Doc 1', null, 1000, null);
        $second = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'Doc 2', 'desc', 2000, 7);

        $docs = $this->repository->findBySectionAndYear($this->sectionId, $this->scoutYearId);
        $this->assertCount(2, $docs);
        $this->assertSame($first, $docs[0]->id);
        $this->assertSame(0, $docs[0]->sortOrder);
        $this->assertSame($second, $docs[1]->id);
        $this->assertSame(1, $docs[1]->sortOrder);
        $this->assertSame(SectionDocument::COMPRESSION_PENDING, $docs[0]->compressionStatus);
    }

    public function testUpdateTitleAndDescription(): void
    {
        $id = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'Old', null, 1000, null);

        $this->repository->updateTitleAndDescription($id, 'New title', 'New description');

        $doc = $this->repository->findById($id);
        $this->assertSame('New title', $doc->title);
        $this->assertSame('New description', $doc->description);
    }

    public function testReorderPersistsNewSortOrder(): void
    {
        $a = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'A', null, 1000, null);
        $b = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'B', null, 1000, null);

        $this->repository->reorder($this->sectionId, $this->scoutYearId, [$b, $a]);

        $docs = $this->repository->findBySectionAndYear($this->sectionId, $this->scoutYearId);
        $this->assertSame($b, $docs[0]->id);
        $this->assertSame($a, $docs[1]->id);
    }

    public function testMarkCompressedSetsStatusAndSizeAfter(): void
    {
        $id = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'Doc', null, 5000, null);

        $this->repository->markCompressed($id, 2000);

        $doc = $this->repository->findById($id);
        $this->assertSame(SectionDocument::COMPRESSION_COMPRESSED, $doc->compressionStatus);
        $this->assertSame(2000, $doc->sizeAfterBytes);
    }

    public function testMarkSkippedSetsStatusOnly(): void
    {
        $id = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'Doc', null, 5000, null);

        $this->repository->markSkipped($id);

        $doc = $this->repository->findById($id);
        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $doc->compressionStatus);
        $this->assertNull($doc->sizeAfterBytes);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'Doc', null, 5000, null);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testFilterPairsWithDocumentsOnlyReturnsPairsThatHaveAtLeastOneDocument(): void
    {
        $this->repository->create($this->sectionId, $this->scoutYearId, $this->fileId, 'Doc', null, 1000, null);

        $result = $this->repository->filterPairsWithDocuments([
            ['section_id' => $this->sectionId, 'scout_year_id' => $this->scoutYearId],
            ['section_id' => 999, 'scout_year_id' => $this->scoutYearId],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($this->sectionId, $result[0]['section_id']);
    }

    public function testFilterPairsWithDocumentsReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], $this->repository->filterPairsWithDocuments([]));
    }
}
