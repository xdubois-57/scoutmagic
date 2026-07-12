<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\FileRepository;
use PHPUnit\Framework\TestCase;

class FileRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $repo;

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

        $this->repo = new FileRepository($this->pdo);
    }

    public function testCreateReturnsIdAndFindByIdRetrievesIt(): void
    {
        $id = $this->repo->create('test/file.jpg', 'photo.jpg', 'image/jpeg', 1024, 'public', null, 1);

        $this->assertGreaterThan(0, $id);

        $record = $this->repo->findById($id);
        $this->assertNotNull($record);
        $this->assertSame($id, $record->id);
        $this->assertSame('test/file.jpg', $record->relativePath);
        $this->assertSame('photo.jpg', $record->originalName);
        $this->assertSame('image/jpeg', $record->mimeType);
        $this->assertSame(1024, $record->sizeBytes);
        $this->assertSame('public', $record->roleMin);
        $this->assertFalse($record->encrypted);
    }

    public function testDeleteRemovesRecord(): void
    {
        $id = $this->repo->create('test/file.jpg', 'photo.jpg', 'image/jpeg', 1024, 'public', null, null);

        $this->repo->delete($id);

        $this->assertNull($this->repo->findById($id));
    }

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testCreateWithModuleId(): void
    {
        $id = $this->repo->create('mod/file.pdf', 'doc.pdf', 'application/pdf', 2048, 'chief', 'my_module', 1);

        $record = $this->repo->findById($id);
        $this->assertNotNull($record);
        $this->assertSame('chief', $record->roleMin);
    }
}
