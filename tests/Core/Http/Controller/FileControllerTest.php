<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Http\Controller\FileController;
use Core\Http\Request;
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
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
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
}
