<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Http\Controller\FileController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class FileControllerTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $fileRepository;
    private FileController $controller;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            relative_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            module_id TEXT,
            role_min TEXT NOT NULL DEFAULT "public",
            custom_resolver TEXT,
            encrypted INTEGER NOT NULL DEFAULT 0,
            owner_member_id INTEGER,
            owner_type TEXT,
            owner_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
        )');
        $this->pdo->exec('CREATE TABLE event_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            logged_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_account_id INTEGER,
            ip_address TEXT,
            category TEXT NOT NULL,
            event_type TEXT NOT NULL,
            level TEXT NOT NULL DEFAULT "info",
            description TEXT NOT NULL,
            context TEXT
        )');
        $this->fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/file_controller_test_' . uniqid();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $guard = new FileAccessGuard($this->fileRepository, Role::INTENDANT);
        $storage = new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath);

        $this->controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, $storage);
    }

    public function testThumbnailReturns403WhenAccessDenied(): void
    {
        $id = $this->fileRepository->create('a.pdf', 'a.pdf', 'application/pdf', 100, 'admin', null, null);

        $response = $this->controller->thumbnail(new Request('GET', "/files/{$id}/thumbnail", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testThumbnailReturns403ForUnknownFile(): void
    {
        $response = $this->controller->thumbnail(new Request('GET', '/files/999/thumbnail', [], [], [], []), ['id' => '999']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testThumbnailReturns415ForNonPdfMimeType(): void
    {
        $id = $this->fileRepository->create('a.jpg', 'a.jpg', 'image/jpeg', 100, 'intendant', null, null);

        $response = $this->controller->thumbnail(new Request('GET', "/files/{$id}/thumbnail", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testThumbnailReturns404WhenRasterizationFails(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $storage = new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath);
        $fileId = $storage->store('not a real pdf', 'application/pdf', 'a.pdf', 'test', 'intendant');

        $response = $this->controller->thumbnail(new Request('GET', "/files/{$fileId}/thumbnail", [], [], [], []), ['id' => (string) $fileId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Member page §5 requirement: successful access to an owner-scoped
     * file (member_documents) is journaled — member_id reference only, no
     * personal data.
     */
    public function testServeJournalsAccessToAnOwnerScopedFile(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::IDENTIFIED, [42]);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath));
        $journalRepo = new JournalRepository($this->pdo);
        $controller->setJournalService(new JournalService($journalRepo));

        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/doc.pdf', 'content');
        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, owner_member_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute(['doc.pdf', 'doc.pdf', 'application/pdf', 7, 'identified', 42]);
        $id = (int) $this->pdo->lastInsertId();

        $response = $controller->serve(new Request('GET', "/files/{$id}", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $stmt = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'owner_scoped_file_accessed'");
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $entries);
        $context = json_decode($entries[0]['context'], true);
        $this->assertSame(42, $context['owner_member_id']);
        $this->assertSame($id, $context['file_id']);
    }

    public function testServeDoesNotJournalAccessToAnOrdinaryFile(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::PUBLIC);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath));
        $journalRepo = new JournalRepository($this->pdo);
        $controller->setJournalService(new JournalService($journalRepo));

        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/pic.jpg', 'content');
        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(['pic.jpg', 'pic.jpg', 'image/jpeg', 7, 'public']);
        $id = (int) $this->pdo->lastInsertId();

        $response = $controller->serve(new Request('GET', "/files/{$id}", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'owner_scoped_file_accessed'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
