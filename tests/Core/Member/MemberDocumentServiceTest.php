<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberDocumentRepository;
use Core\Member\MemberDocumentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberDocumentServiceTest extends TestCase
{
    public function testListForMemberDelegatesToTheRepository(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $repo = new MemberDocumentRepository($pdo);
        $service = new MemberDocumentService($repo);

        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $memberId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, owner_member_id) VALUES ('doc.pdf.enc', 'attestation.pdf', 'application/pdf', 100, 'identified', {$memberId})");
        $fileId = (int) $pdo->lastInsertId();
        $repo->create($memberId, $scoutYearId, 'Attestation fiscale', $fileId, null);

        $documents = $service->listForMember($memberId, $scoutYearId);

        $this->assertCount(1, $documents);
        $this->assertSame('Attestation fiscale', $documents[0]->title);
    }

    public function testListForMemberReturnsEmptyArrayWhenNoDocuments(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $service = new MemberDocumentService(new MemberDocumentRepository($pdo));

        $this->assertSame([], $service->listForMember(999, 1));
    }
}
