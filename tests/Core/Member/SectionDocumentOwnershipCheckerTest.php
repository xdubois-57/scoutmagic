<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Member\SectionDocumentOwnershipChecker;
use Core\Member\SectionDocumentRepository;
use Core\Member\SectionMembershipRepository;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class SectionDocumentOwnershipCheckerTest extends TestCase
{
    private \PDO $pdo;
    private SectionDocumentOwnershipChecker $checker;
    private SectionMembershipRepository $membershipRepository;
    private int $sectionId;
    private int $otherSectionId;
    private int $scoutYearId;
    private int $documentId;
    private int $memberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $documentRepository = new SectionDocumentRepository($this->pdo);
        $this->membershipRepository = new SectionMembershipRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('section_document_reference_date', '30-09', 'text', 'x', 'x');

        $this->checker = new SectionDocumentOwnershipChecker(
            $documentRepository, $this->membershipRepository, new ScoutYearService($this->pdo), $settingService
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('SEC_A', {$branchId})");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('SEC_B', {$branchId})");
        $this->otherSectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, encrypted) VALUES ('a.pdf.enc', 'a.pdf', 'application/pdf', 1000, 'identified', 1)");
        $fileId = (int) $this->pdo->lastInsertId();

        $this->documentId = $documentRepository->create($this->sectionId, $this->scoutYearId, $fileId, 'Doc', null, 1000, null);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    public function testSupportsOnlySectionDocumentOwnerType(): void
    {
        $this->assertTrue($this->checker->supports('section_document'));
        $this->assertFalse($this->checker->supports('member'));
    }

    public function testChiefIsAlwaysAllowedRegardlessOfMembership(): void
    {
        $this->assertTrue($this->checker->isAllowed($this->documentId, Role::CHIEF, []));
    }

    public function testAMemberActiveInTheDocumentsSectionOnTheReferenceDateIsAllowed(): void
    {
        // Reference date for 2025-2026 with default '30-09' is 2025-09-30.
        $this->membershipRepository->open($this->memberId, $this->sectionId, $this->scoutYearId, '2025-09-01');

        $this->assertTrue($this->checker->isAllowed($this->documentId, Role::IDENTIFIED, [$this->memberId]));
    }

    public function testAPastMemberWhoseMembershipCoveredTheReferenceDateIsAllowed(): void
    {
        $this->membershipRepository->open($this->memberId, $this->sectionId, $this->scoutYearId, '2025-09-01');
        $this->membershipRepository->closeOpenPeriods($this->memberId, $this->sectionId, $this->scoutYearId, '2026-06-01');

        $this->assertTrue($this->checker->isAllowed($this->documentId, Role::IDENTIFIED, [$this->memberId]));
    }

    public function testAMemberOfADifferentSectionIsRefused(): void
    {
        $this->membershipRepository->open($this->memberId, $this->otherSectionId, $this->scoutYearId, '2025-09-01');

        $this->assertFalse($this->checker->isAllowed($this->documentId, Role::IDENTIFIED, [$this->memberId]));
    }

    public function testAMemberWhoJoinedAfterTheReferenceDateIsRefused(): void
    {
        // Joined in November — after the 2025-09-30 reference date.
        $this->membershipRepository->open($this->memberId, $this->sectionId, $this->scoutYearId, '2025-11-01');

        $this->assertFalse($this->checker->isAllowed($this->documentId, Role::IDENTIFIED, [$this->memberId]));
    }

    public function testWithNoLinkedMembersAtAllIsRefused(): void
    {
        $this->assertFalse($this->checker->isAllowed($this->documentId, Role::IDENTIFIED, []));
    }

    public function testUnknownDocumentIdIsRefused(): void
    {
        $this->assertFalse($this->checker->isAllowed(999999, Role::IDENTIFIED, [$this->memberId]));
    }
}
