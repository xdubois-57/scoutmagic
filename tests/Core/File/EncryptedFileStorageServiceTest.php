<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;

class EncryptedFileStorageServiceTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $fileRepository;
    private EncryptedFileStorageService $service;
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
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/finance_encrypted_test_' . uniqid();
        $this->service = new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testStoreAndRetrieveRoundTrip(): void
    {
        $content = "%PDF-1.4 fake pdf binary content \x00\x01\x02";

        $fileId = $this->service->store($content, 'application/pdf', 'facture.pdf', 'finance/receipts', 'intendant');

        $this->assertGreaterThan(0, $fileId);
        $this->assertSame($content, $this->service->retrieve($fileId));
    }

    public function testStoredFileMarkedEncryptedInFileRecord(): void
    {
        $fileId = $this->service->store('content', 'application/pdf', 'a.pdf', 'finance/receipts', 'intendant');

        $file = $this->fileRepository->findById($fileId);
        $this->assertTrue($file->encrypted);
    }

    public function testContentNeverStoredInPlaintextOnDisk(): void
    {
        $secret = 'this exact string must never appear on disk unencrypted';
        $fileId = $this->service->store($secret, 'application/pdf', 'a.pdf', 'finance/receipts', 'intendant');

        $file = $this->fileRepository->findById($fileId);
        $onDisk = file_get_contents($this->storagePath . '/' . $file->relativePath);

        $this->assertStringNotContainsString($secret, $onDisk);
    }

    public function testRetrieveThrowsForUnknownFileId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->retrieve(9999);
    }

    public function testDeleteRemovesFileFromDiskAndRecord(): void
    {
        $fileId = $this->service->store('content', 'application/pdf', 'a.pdf', 'finance/receipts', 'intendant');
        $file = $this->fileRepository->findById($fileId);
        $diskPath = $this->storagePath . '/' . $file->relativePath;
        $this->assertFileExists($diskPath);

        $this->service->delete($fileId);

        $this->assertFileDoesNotExist($diskPath);
        $this->assertNull($this->fileRepository->findById($fileId));
    }

    public function testDeleteOfUnknownFileIdIsANoOp(): void
    {
        $this->service->delete(9999);
        $this->addToAssertionCount(1);
    }

    public function testExtensionMappedFromKnownMimeTypes(): void
    {
        $fileId = $this->service->store('x', 'image/jpeg', 'photo.jpg', 'finance/receipts', 'intendant');
        $file = $this->fileRepository->findById($fileId);

        $this->assertStringEndsWith('.jpg.enc', $file->relativePath);
    }
}
