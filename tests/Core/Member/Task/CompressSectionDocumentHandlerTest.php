<?php

declare(strict_types=1);

namespace Tests\Core\Member\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\SectionDocument;
use Core\Member\SectionDocumentRepository;
use Core\Member\Task\CompressSectionDocumentHandler;
use Core\Pdf\PdfCompressor;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class CompressSectionDocumentHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private TaskContext $context;
    private SectionDocumentRepository $documentRepository;
    private FileRepository $fileRepository;
    private EncryptedFileStorageService $fileStorage;
    private string $storagePath;
    private int $sectionId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->documentRepository = new SectionDocumentRepository($this->pdo);
        $this->fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/compress_handler_test_' . uniqid();
        $this->fileStorage = new EncryptedFileStorageService($this->fileRepository, $this->encryption, $this->storagePath);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('section_document_compression_enabled', '1', 'boolean', 'x', 'x');
        $settingService->register('section_document_compression_quality', PdfCompressor::QUALITY_BALANCED, 'select', 'x', 'x');

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $settingService,
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('SEC_A', {$branchId})");
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

    private function createDocument(string $mimeType, string $content): SectionDocument
    {
        $fileId = $this->fileStorage->store($content, $mimeType, 'a.pdf', 'section_documents', 'identified');
        $id = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $fileId, 'Doc', null, strlen($content), null);
        $document = $this->documentRepository->findById($id);
        \assert($document !== null);
        return $document;
    }

    private function fakeCompressor(string $backend, \Closure $compressOutcome): PdfCompressor
    {
        return new class ($this->storagePath . '/temp', $backend, $compressOutcome) extends PdfCompressor {
            public function __construct(string $tempDirectory, private string $backend, private \Closure $compressOutcome)
            {
                parent::__construct($tempDirectory);
            }

            public function detectBackend(): string
            {
                return $this->backend;
            }

            protected function executeBackend(string $backend, string $inputPath, string $outputPath, string $quality): bool
            {
                $result = ($this->compressOutcome)();
                if ($result === null) {
                    return false;
                }
                return file_put_contents($outputPath, $result) !== false;
            }
        };
    }

    public function testNonPdfFileIsSkippedWithoutAttemptingCompression(): void
    {
        $document = $this->createDocument('text/plain', 'plain text content');

        (new CompressSectionDocumentHandler())->handle(['section_document_id' => $document->id], $this->context);

        $updated = $this->documentRepository->findById($document->id);
        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $updated->compressionStatus);
    }

    public function testUnknownDocumentIdIsANoOp(): void
    {
        (new CompressSectionDocumentHandler())->handle(['section_document_id' => 999999], $this->context);
        // No exception — nothing to assert beyond "didn't crash".
        $this->assertTrue(true);
    }

    public function testZeroOrMissingDocumentIdIsANoOp(): void
    {
        (new CompressSectionDocumentHandler())->handle([], $this->context);
        $this->assertTrue(true);
    }

    public function testDisabledSettingSkipsWithoutCompressing(): void
    {
        $document = $this->createDocument('application/pdf', '%PDF-1.4 ' . str_repeat('x', 1000));
        $this->context->settings->setInternal('section_document_compression_enabled', '0');

        $handler = new CompressSectionDocumentHandler($this->fakeCompressor(
            PdfCompressor::BACKEND_GHOSTSCRIPT,
            fn() => '%PDF-1.4x'
        ));
        $handler->handle(['section_document_id' => $document->id], $this->context);

        $updated = $this->documentRepository->findById($document->id);
        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $updated->compressionStatus);
        $this->assertNull($updated->sizeAfterBytes);
    }

    public function testNoBackendAvailableSkips(): void
    {
        $document = $this->createDocument('application/pdf', '%PDF-1.4 ' . str_repeat('x', 1000));

        $handler = new CompressSectionDocumentHandler($this->fakeCompressor(PdfCompressor::BACKEND_NONE, fn() => null));
        $handler->handle(['section_document_id' => $document->id], $this->context);

        $updated = $this->documentRepository->findById($document->id);
        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $updated->compressionStatus);
    }

    public function testCompressionWithNoSizeWinIsSkippedAndJournaled(): void
    {
        $original = '%PDF-1.4 ' . str_repeat('x', 1000);
        $document = $this->createDocument('application/pdf', $original);

        // A "compression" that produces something the same size or bigger
        // must never replace the original — PdfCompressor::compress()
        // itself already refuses this internally (see PdfCompressorTest),
        // this proves the handler surfaces that as 'skipped', not a crash.
        $handler = new CompressSectionDocumentHandler($this->fakeCompressor(
            PdfCompressor::BACKEND_GHOSTSCRIPT,
            fn() => '%PDF-1.4 ' . str_repeat('y', 2000)
        ));
        $handler->handle(['section_document_id' => $document->id], $this->context);

        $updated = $this->documentRepository->findById($document->id);
        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $updated->compressionStatus);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'section_document_compression_skipped'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // The stored file is untouched.
        $this->assertSame($original, $this->fileStorage->retrieve($document->fileId));
    }

    public function testSuccessfulCompressionReplacesFileAndJournals(): void
    {
        $original = '%PDF-1.4 ' . str_repeat('x', 5000);
        $document = $this->createDocument('application/pdf', $original);
        $compressed = '%PDF-1.4y';

        $handler = new CompressSectionDocumentHandler($this->fakeCompressor(
            PdfCompressor::BACKEND_GHOSTSCRIPT,
            fn() => $compressed
        ));
        $handler->handle(['section_document_id' => $document->id], $this->context);

        $updated = $this->documentRepository->findById($document->id);
        $this->assertSame(SectionDocument::COMPRESSION_COMPRESSED, $updated->compressionStatus);
        $this->assertSame(strlen($compressed), $updated->sizeAfterBytes);
        $this->assertSame($compressed, $this->fileStorage->retrieve($document->fileId));
        // Same file_id throughout — nothing referencing the document had
        // to change.
        $this->assertSame($document->fileId, $updated->fileId);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'section_document_compressed'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testANonPdfFileNeverInvokesTheCompressor(): void
    {
        $document = $this->createDocument('text/csv', 'a,b,c');

        $handler = new CompressSectionDocumentHandler($this->fakeCompressor(
            PdfCompressor::BACKEND_GHOSTSCRIPT,
            function () {
                $this->fail('Compressor should never be invoked for a non-PDF file.');
            }
        ));
        $handler->handle(['section_document_id' => $document->id], $this->context);

        $this->assertSame(SectionDocument::COMPRESSION_SKIPPED, $this->documentRepository->findById($document->id)->compressionStatus);
    }
}
