<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionDocumentException;
use Core\Member\SectionDocumentRepository;
use Core\Member\SectionDocumentService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\Pdf\PdfCompressor;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class SectionDocumentServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionDocumentService $service;
    private SectionDocumentRepository $repository;
    private SectionMembershipRepository $membershipRepository;
    private FileRepository $fileRepository;
    private string $storagePath;
    private int $sectionId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->repository = new SectionDocumentRepository($this->pdo);
        $this->membershipRepository = new SectionMembershipRepository($this->pdo);
        $this->fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/section_document_test_' . uniqid();
        $fileStorage = new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('section_document_compression_enabled', '1', 'boolean', 'x', 'x');
        $settingService->register('section_document_compression_quality', PdfCompressor::QUALITY_BALANCED, 'select', 'x', 'x');
        $settingService->register('section_document_compression_backend', PdfCompressor::BACKEND_NONE, 'text', 'x', 'x', null, null, null, false);

        $this->service = new SectionDocumentService(
            $this->repository,
            $this->membershipRepository,
            $fileStorage,
            $this->fileRepository,
            $sectionService,
            new ScoutYearService($this->pdo),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $settingService,
            new PdfCompressor($this->storagePath . '/temp')
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('SEC_A', {$branchId}, 'Meute')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testUploadCreatesADocumentAndSetsFileOwnership(): void
    {
        $document = $this->service->upload($this->sectionId, $this->scoutYearId, '%PDF-1.4 fake content', 'application/pdf', 'camp.pdf', 'Camp booklet', 'desc', 7);

        $this->assertSame('Camp booklet', $document->title);
        $file = $this->fileRepository->findById($document->fileId);
        $this->assertSame('section_document', $file->ownerType);
        $this->assertSame($document->id, $file->ownerId);
        $this->assertTrue($file->encrypted);
    }

    public function testUploadDefaultsTitleToTheOriginalFilenameWhenBlank(): void
    {
        $document = $this->service->upload($this->sectionId, $this->scoutYearId, 'content', 'text/plain', 'materiel.txt', '', null, null);

        $this->assertSame('materiel.txt', $document->title);
    }

    public function testUploadRejectsAnUnsupportedMimeType(): void
    {
        $this->expectException(SectionDocumentException::class);
        $this->service->upload($this->sectionId, $this->scoutYearId, 'x', 'application/zip', 'a.zip', 'Doc', null, null);
    }

    public function testUploadRejectsAnImageWithAGalerieRedirectMessage(): void
    {
        try {
            $this->service->upload($this->sectionId, $this->scoutYearId, 'x', 'image/jpeg', 'a.jpg', 'Doc', null, null);
            $this->fail('Expected SectionDocumentException');
        } catch (SectionDocumentException $e) {
            $this->assertStringContainsString('Galerie', $e->getMessage());
        }
    }

    public function testUploadRejectsAVideoWithAGalerieRedirectMessage(): void
    {
        try {
            $this->service->upload($this->sectionId, $this->scoutYearId, 'x', 'video/mp4', 'a.mp4', 'Doc', null, null);
            $this->fail('Expected SectionDocumentException');
        } catch (SectionDocumentException $e) {
            $this->assertStringContainsString('Galerie', $e->getMessage());
        }
    }

    public function testUpdateTitleAndDescriptionRejectsABlankTitle(): void
    {
        $document = $this->service->upload($this->sectionId, $this->scoutYearId, 'content', 'text/plain', 'a.txt', 'Doc', null, null);

        $this->expectException(SectionDocumentException::class);
        $this->service->updateTitleAndDescription($document->id, '  ', null, null);
    }

    public function testUpdateTitleAndDescriptionPersists(): void
    {
        $document = $this->service->upload($this->sectionId, $this->scoutYearId, 'content', 'text/plain', 'a.txt', 'Doc', null, null);

        $this->service->updateTitleAndDescription($document->id, 'New title', 'New desc', null);

        $updated = $this->repository->findById($document->id);
        $this->assertSame('New title', $updated->title);
        $this->assertSame('New desc', $updated->description);
    }

    public function testDeleteRemovesTheRowAndTheEncryptedFile(): void
    {
        $document = $this->service->upload($this->sectionId, $this->scoutYearId, 'content', 'text/plain', 'a.txt', 'Doc', null, null);
        $fileId = $document->fileId;

        $this->service->delete($document->id, null);

        $this->assertNull($this->repository->findById($document->id));
        $this->assertNull($this->fileRepository->findById($fileId));
    }

    public function testDeleteOfAnUnknownDocumentThrows(): void
    {
        $this->expectException(SectionDocumentException::class);
        $this->service->delete(999999, null);
    }

    public function testReorderPersists(): void
    {
        $a = $this->service->upload($this->sectionId, $this->scoutYearId, 'x', 'text/plain', 'a.txt', 'A', null, null);
        $b = $this->service->upload($this->sectionId, $this->scoutYearId, 'y', 'text/plain', 'b.txt', 'B', null, null);

        $this->service->reorder([$b->id, $a->id]);

        $docs = $this->service->listForSectionAndYear($this->sectionId, $this->scoutYearId);
        $this->assertSame($b->id, $docs[0]->id);
    }

    public function testListForMemberPageOmitsYearsWithoutDocuments(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->membershipRepository->open($memberId, $this->sectionId, $this->scoutYearId, '2025-09-01');

        // No documents uploaded yet — nothing to show.
        $this->assertSame([], $this->service->listForMemberPage($memberId));

        $this->service->upload($this->sectionId, $this->scoutYearId, 'x', 'text/plain', 'a.txt', 'A', null, null);

        $result = $this->service->listForMemberPage($memberId);
        $this->assertCount(1, $result);
        $yearBlock = reset($result);
        $this->assertSame($this->sectionId, $yearBlock['section']['id']);
        $this->assertSame($this->scoutYearId, $yearBlock['scout_year']['id']);
        $this->assertCount(1, $yearBlock['documents']);
    }

    public function testListForMemberPageIncludesAHiddenSection(): void
    {
        $this->pdo->exec("UPDATE sections SET is_visible = 0 WHERE id = {$this->sectionId}");
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->membershipRepository->open($memberId, $this->sectionId, $this->scoutYearId, '2025-09-01');
        $this->service->upload($this->sectionId, $this->scoutYearId, 'x', 'text/plain', 'a.txt', 'A', null, null);

        $result = $this->service->listForMemberPage($memberId);
        $this->assertCount(1, $result);
    }

    public function testListForMemberPageReturnsEmptyForAMemberWithNoPeriods(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $memberId = (int) $this->pdo->lastInsertId();

        $this->assertSame([], $this->service->listForMemberPage($memberId));
    }
}
