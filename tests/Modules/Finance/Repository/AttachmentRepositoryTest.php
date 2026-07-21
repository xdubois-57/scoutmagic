<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class AttachmentRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AttachmentRepository $repository;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new AttachmentRepository($this->pdo);

        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        $stmt->execute();
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', 42.5, '2026-10-01', null, 7);

        $attachment = $this->repository->findById($id);
        $this->assertNotNull($attachment);
        $this->assertSame('facture.pdf', $attachment->originalFilename);
        $this->assertSame(42.5, $attachment->suggestedAmount);
        $this->assertSame(Attachment::STATUS_ACTIVE, $attachment->status);
    }

    public function testUpdateSuggestedLabelSetsMerchantName(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->assertNull($this->repository->findById($id)->suggestedLabel);

        $this->repository->updateSuggestedLabel($id, 'Delhaize');

        $this->assertSame('Delhaize', $this->repository->findById($id)->suggestedLabel);
    }

    public function testUpdateSuggestedDescriptionSetsOneSentenceSummary(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->assertNull($this->repository->findById($id)->suggestedDescription);

        $this->repository->updateSuggestedDescription($id, 'Achat de fournitures de bureau');

        $this->assertSame('Achat de fournitures de bureau', $this->repository->findById($id)->suggestedDescription);
    }

    public function testFindActiveOrderedExcludesArchived(): void
    {
        $id1 = $this->repository->create(null, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);
        $id2 = $this->repository->create(null, $this->fileId, 'application/pdf', 'b.pdf', null, null, null, null);

        $this->repository->archive($id1);

        $active = $this->repository->findActiveOrdered();
        $this->assertCount(1, $active);
        $this->assertSame($id2, $active[0]->id);
    }

    public function testArchiveNeverDeletesTheRow(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);

        $this->repository->archive($id);

        $attachment = $this->repository->findById($id);
        $this->assertNotNull($attachment);
        $this->assertSame(Attachment::STATUS_ARCHIVED, $attachment->status);
    }

    public function testArchiveAllReturnsCountAndOnlyTouchesActive(): void
    {
        $id1 = $this->repository->create(null, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);
        $id2 = $this->repository->create(null, $this->fileId, 'application/pdf', 'b.pdf', null, null, null, null);
        $this->repository->archive($id1);

        $archived = $this->repository->archiveAll();

        $this->assertSame(1, $archived);
        $this->assertSame(Attachment::STATUS_ARCHIVED, $this->repository->findById($id2)->status);
    }

    public function testParentAttachmentIdChainsVersions(): void
    {
        $originalId = $this->repository->create(null, $this->fileId, 'application/pdf', 'v1.pdf', null, null, null, null);
        $replacementId = $this->repository->create(null, $this->fileId, 'application/pdf', 'v2.pdf', null, null, $originalId, null);

        $this->assertSame($originalId, $this->repository->findById($replacementId)->parentAttachmentId);
    }
}
