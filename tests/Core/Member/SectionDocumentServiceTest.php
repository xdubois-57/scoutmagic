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
use Core\Member\SectionDocument;
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
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionDocumentServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionDocumentService $service;
    /**
     * The very instance the service under test reads from.
     *
     * `SettingService` caches what it has loaded, per instance, which is
     * what a real request does too. A test that wrote through a second
     * instance changed the row and nothing else: the service went on
     * reading its own cache, and the assertion failed for a reason that
     * had nothing to do with the code under test.
     */
    private SettingService $settingService;
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
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
);

        $settingService = $this->settingService = new SettingService(new SettingRepository($this->pdo));
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

    /**
     * **A document nobody will compress says so, instead of saying « soon ».**
     *
     * The row is inserted 'pending' whatever the type, and 'pending' is what
     * `chefs/staffs.html.twig` renders as « Compression en cours… ». Only a
     * PDF is ever scheduled, and `markSkipped()` lives inside the handler, so
     * eleven of the twelve accepted types reached neither: their badge said a
     * treatment was running, for ever, for a treatment that was never going
     * to exist (issue #556).
     *
     * A spreadsheet stands in for the other ten here; the MIME whitelist is
     * pinned on its own by testUploadRejectsAnUnsupportedMimeType() and its
     * two neighbours, so repeating all eleven would assert the list twice and
     * the behaviour once.
     */
    public function testANonPdfIsMarkedSkippedRatherThanLeftPending(): void
    {
        $document = $this->service->upload(
            $this->sectionId,
            $this->scoutYearId,
            'colonne;valeur',
            'text/csv',
            'materiel.csv',
            'Liste de matériel',
            null,
            null
        );

        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $document->compressionStatus);
        // Read back, not just returned: the badge is rendered from the row.
        $stored = $this->repository->findById($document->id);
        $this->assertNotNull($stored);
        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $stored->compressionStatus);
    }

    /**
     * The other half of the same branch, and the half that says the fix did
     * not simply switch everything off: a PDF is still scheduled, and still
     * waits.
     */
    public function testAPdfStaysPendingAndIsScheduled(): void
    {
        $document = $this->service->upload(
            $this->sectionId,
            $this->scoutYearId,
            '%PDF-1.4 fake content',
            'application/pdf',
            'camp.pdf',
            'Carnet de camp',
            null,
            null
        );

        $this->assertSame(SectionDocument::COMPRESSION_PENDING, $document->compressionStatus);
        $scheduled = (new SchedulerRepository($this->pdo))
            ->findByModuleAndTaskKey('core', 'compress_section_document');
        $this->assertNotEmpty($scheduled, 'a PDF upload must still schedule the background pass');
    }

    /**
     * A PDF uploaded while compression is switched off is the same trap as a
     * spreadsheet: nothing is scheduled, so nothing would ever clear the
     * badge. The setting is read at upload time, once — this is what says so.
     */
    public function testAPdfUploadedWithCompressionOffIsSkippedToo(): void
    {
        $this->settingService->set('section_document_compression_enabled', '0');

        $document = $this->service->upload(
            $this->sectionId,
            $this->scoutYearId,
            '%PDF-1.4 fake content',
            'application/pdf',
            'camp.pdf',
            'Carnet de camp',
            null,
            null
        );

        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $document->compressionStatus);
        $this->assertEmpty(
            (new SchedulerRepository($this->pdo))->findByModuleAndTaskKey('core', 'compress_section_document'),
            'nothing should be scheduled when compression is off'
        );
    }

    /**
     * #243. store() writes the encrypted file AND its `files` row; the
     * document row that owns them is a second write with no transaction
     * across the two. A failure there left both behind, owned by nothing
     * and collected by nothing — where three other paths in this codebase
     * already compensate (Modules\Finance\Service\CampaignService::
     * createFromFile(), Core\Import\DeskImportService::import(),
     * Modules\Finance\Service\BatchDepositService::deposit()).
     */
    public function testAFailedDocumentRowTakesTheStoredFileWithIt(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $this->pdo->exec('DROP TABLE section_documents');

        try {
            $this->service->upload($this->sectionId, $this->scoutYearId, '%PDF-1.4 fake content',
                'application/pdf', 'camp.pdf', 'Camp booklet', null, 7);
            $this->fail('an exception was expected');
        } catch (\Throwable) {
            // The point is what is left behind.
        }

        $this->assertSame(
            $before,
            (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
            'an orphaned `files` row was left behind',
        );
        $stored = glob($this->storagePath . '/section_documents/*') ?: [];
        $this->assertSame([], $stored, 'an orphaned encrypted file was left on disk');
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
