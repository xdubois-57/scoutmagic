<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberDocumentRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberDocumentRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MemberDocumentRepository $repo;
    private int $memberId;
    private int $scoutYearId;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new MemberDocumentRepository($this->pdo);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, owner_member_id) VALUES ('doc.pdf.enc', 'attestation.pdf', 'application/pdf', 100, 'identified', {$this->memberId})");
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindByMemberAndYear(): void
    {
        $id = $this->repo->create($this->memberId, $this->scoutYearId, 'Attestation fiscale 2025', $this->fileId, null);

        $documents = $this->repo->findByMemberAndYear($this->memberId, $this->scoutYearId);

        $this->assertCount(1, $documents);
        $this->assertSame($id, $documents[0]->id);
        $this->assertSame('Attestation fiscale 2025', $documents[0]->title);
        $this->assertSame($this->fileId, $documents[0]->fileId);
    }

    public function testFindByMemberAndYearReturnsEmptyArrayWhenNoDocuments(): void
    {
        $documents = $this->repo->findByMemberAndYear($this->memberId, $this->scoutYearId);

        $this->assertSame([], $documents);
    }

    public function testFindByMemberAndYearOrdersMostRecentFirst(): void
    {
        $firstId = $this->repo->create($this->memberId, $this->scoutYearId, 'Older document', $this->fileId, null);
        sleep(1);
        $secondId = $this->repo->create($this->memberId, $this->scoutYearId, 'Newer document', $this->fileId, null);

        $documents = $this->repo->findByMemberAndYear($this->memberId, $this->scoutYearId);

        $this->assertSame($secondId, $documents[0]->id);
        $this->assertSame($firstId, $documents[1]->id);
    }

    public function testFindByMemberAndYearScopesToTheGivenMemberOnly(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK2')");
        $otherMemberId = (int) $this->pdo->lastInsertId();
        $this->repo->create($otherMemberId, $this->scoutYearId, 'Not mine', $this->fileId, null);

        $documents = $this->repo->findByMemberAndYear($this->memberId, $this->scoutYearId);

        $this->assertSame([], $documents);
    }

    public function testFindById(): void
    {
        $id = $this->repo->create($this->memberId, $this->scoutYearId, 'Attestation fiscale 2025', $this->fileId, null);

        $document = $this->repo->findById($id);

        $this->assertNotNull($document);
        $this->assertSame('Attestation fiscale 2025', $document->title);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999999));
    }
}
