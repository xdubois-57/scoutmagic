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

    // ── upload() ────────────────────────────────────────────────────

    /**
     * The happy path, end to end: bytes land under storage/ (never under
     * public/), a `files` row is written with this module's owner type so
     * CampFileOwnershipChecker gates it, the document points at that row,
     * and the stay's history records it.
     */
    public function testUploadingADocumentStoresTheBytesTheRowAndTheHistoryLine(): void
    {
        $id = $this->service->upload(1, $this->anUploadedPdf(), 'Contrat signé', 42);

        $document = $this->documents->findById($id);
        $this->assertNotNull($document);
        $this->assertSame('Contrat signé', $document->title);
        $this->assertSame(1, $document->campId);
        $this->assertTrue($document->ownsItsFile(), 'a manual upload is this module\'s to delete');

        $file = $this->files->findById($document->fileId);
        $this->assertNotNull($file);
        $this->assertSame('application/pdf', $file->mimeType);
        $this->assertSame('chief', $file->roleMin);
        $this->assertStringStartsWith('camps/1/documents/', $file->relativePath);
        $this->assertFileExists($this->storagePath . '/' . $file->relativePath);

        $entry = $this->audit->page(CampService::ENTITY_TYPE, 1, 1, 10)->entries[0];
        $this->assertSame('document', $entry->fieldKey);
        $this->assertNull($entry->fromValue);
        $this->assertSame('Contrat signé', $entry->toValue);
    }

    /**
     * The stored name is generated, never the visitor's — a filename is
     * attacker-controlled text and the path it lands on must not be.
     */
    public function testTheStoredNameIsNeverTheNameTheBrowserSent(): void
    {
        $id = $this->service->upload(1, $this->anUploadedPdf('../../evil.pdf'), null, 42);

        $file = $this->files->findById($this->documents->findById($id)?->fileId ?? 0);
        $this->assertNotNull($file);
        $this->assertStringNotContainsString('..', $file->relativePath);
        $this->assertStringNotContainsString('evil', $file->relativePath);
    }

    /**
     * Falling back to the original filename beats "Document 4": a chief who
     * uploaded "contrat-mozet-2028.pdf" has already named it.
     */
    public function testAnUntitledUploadTakesItsNameFromTheFile(): void
    {
        $id = $this->service->upload(1, $this->anUploadedPdf('contrat-mozet-2028.pdf'), '   ', 42);

        $this->assertSame('contrat-mozet-2028.pdf', $this->documents->findById($id)?->title);
    }

    public function testARefusedUploadWritesNoRowAtAll(): void
    {
        $path = $this->storagePath . '/refused.txt';
        file_put_contents($path, 'not a pdf at all');

        try {
            $this->service->upload(
                1,
                ['tmp_name' => $path, 'name' => 'notes.txt', 'size' => filesize($path), 'error' => UPLOAD_ERR_OK],
                'Notes',
                42
            );
            $this->fail('a text file is not an allowed document type');
        } catch (CampsException $e) {
            $this->assertStringContainsString('Type de fichier', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_documents')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    /**
     * @return array<string, mixed> a $_FILES entry over a real temporary file
     */
    private function anUploadedPdf(string $browserName = 'contrat.pdf'): array
    {
        $path = $this->storagePath . '/incoming-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return [
            'tmp_name' => $path,
            'name' => $browserName,
            'size' => filesize($path),
            'error' => UPLOAD_ERR_OK,
        ];
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
