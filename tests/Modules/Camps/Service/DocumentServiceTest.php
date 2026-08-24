<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\Document;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\DocumentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class DocumentServiceTest extends TestCase
{
    private \PDO $pdo;
    private DocumentRepository $documents;
    private FileRepository $files;
    private AuditService $audit;
    private DocumentService $service;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->documents = new DocumentRepository($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));

        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-camps-docs-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->service = new DocumentService(
            $this->documents,
            new \Core\File\AttachedFileRemover($this->files, $this->storagePath),
            new UploadHandler($this->files, $this->storagePath),
            $this->audit
        );

        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
        $this->pdo->exec("INSERT INTO camp_camps (place_id, year_only, status) VALUES (1, 2028, 'confirmed')");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
            }
            rmdir($this->storagePath);
        }
    }

    public function testDeletingAManualDocumentRemovesItsFileToo(): void
    {
        $fileId = $this->storeFile();
        $id = $this->documents->create(1, 'Contrat', $fileId, Document::SOURCE_MANUAL);
        $document = $this->documents->findById($id);
        $this->assertNotNull($document);
        $relativePath = $this->files->findById($fileId)?->relativePath;
        $this->assertNotNull($relativePath);

        $this->service->delete($document, 42);

        $this->assertNull($this->documents->findById($id));
        $this->assertNull($this->files->findById($fileId));
        $this->assertFileDoesNotExist($this->storagePath . '/' . $relativePath);
    }

    public function testDeletingAnEmailDocumentKeepsItsFile(): void
    {
        $fileId = $this->storeFile();
        $id = $this->documents->create(1, 'Contrat', $fileId, Document::SOURCE_EMAIL, 'msg-42');
        $document = $this->documents->findById($id);
        $this->assertNotNull($document);
        $relativePath = $this->files->findById($fileId)?->relativePath;
        $this->assertNotNull($relativePath);

        $this->service->delete($document, 42);

        // The bytes belong to the message that carried them, which still
        // owns and serves them: deleting here would blank the message.
        $this->assertNull($this->documents->findById($id));
        $this->assertNotNull($this->files->findById($fileId));
        $this->assertFileExists($this->storagePath . '/' . $relativePath);
    }

    public function testAFileStillUsedByAnotherStayIsNotDeleted(): void
    {
        $this->pdo->exec("INSERT INTO camp_camps (place_id, year_only, status) VALUES (1, 2029, 'confirmed')");
        $fileId = $this->storeFile();
        $first = $this->documents->create(1, 'Contrat', $fileId, Document::SOURCE_MANUAL);
        $this->documents->create(2, 'Le même contrat', $fileId, Document::SOURCE_MANUAL);
        $document = $this->documents->findById($first);
        $this->assertNotNull($document);

        $this->service->delete($document, 42);

        $this->assertNotNull($this->files->findById($fileId));
    }

    public function testAnUploadedDocumentIsRecordedOnTheTimeline(): void
    {
        $fileId = $this->storeFile();
        $id = $this->documents->create(1, 'Contrat 2028', $fileId);
        $document = $this->documents->findById($id);
        $this->assertNotNull($document);

        $this->service->rename($document, 'Contrat signé 2028', 42);

        $entry = $this->audit->page(CampService::ENTITY_TYPE, 1, 1, 10)->entries[0];
        $this->assertSame('document', $entry->fieldKey);
        $this->assertSame('Contrat 2028', $entry->fromValue);
        $this->assertSame('Contrat signé 2028', $entry->toValue);
    }

    public function testRenamingToNothingIsRefused(): void
    {
        $document = $this->documents->findById($this->documents->create(1, 'Contrat', $this->storeFile()));
        $this->assertNotNull($document);

        $this->expectException(CampsException::class);
        $this->service->rename($document, '   ', 42);
    }

    public function testAttachingAnExistingFileNeverCopiesTheBytes(): void
    {
        $fileId = $this->storeFile();

        $id = $this->service->attachExistingFile(1, $fileId, 'Devis reçu', 'msg-42', 42);

        $document = $this->documents->findById($id);
        $this->assertSame($fileId, $document?->fileId);
        $this->assertSame(Document::SOURCE_EMAIL, $document->source);
        $this->assertFalse($document->ownsItsFile());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testDocumentsKeepTheirOrderEvenAfterADeletion(): void
    {
        $first = $this->documents->create(1, 'A', $this->storeFile());
        $second = $this->documents->create(1, 'B', $this->storeFile());
        $this->documents->delete($second);
        $third = $this->documents->create(1, 'C', $this->storeFile());

        // MAX+1, never COUNT: after a deletion the two disagree and a
        // count-derived rank collides with an existing row.
        $titles = array_map(static fn(Document $d): string => $d->title, $this->documents->findByCamp(1));
        $this->assertSame(['A', 'C'], $titles);
        $this->assertNotSame(
            $this->documents->findById($first)?->sortOrder,
            $this->documents->findById($third)?->sortOrder
        );
    }

    private function storeFile(): int
    {
        $relativePath = 'camps/1/documents/' . bin2hex(random_bytes(6)) . '.pdf';
        $full = $this->storagePath . '/' . $relativePath;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, '%PDF-1.4 test');

        return $this->files->create(
            $relativePath, 'contrat.pdf', 'application/pdf', 13, 'chief', 'camps', null, false, null,
            \Modules\Camps\Service\CampFileOwnershipChecker::OWNER_TYPE, 1
        );
    }
}
