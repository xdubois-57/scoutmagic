<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\AttachedFileRemover;
use Core\File\AttachedFileRepository;
use Core\File\FileRepository;
use PHPUnit\Framework\TestCase;

/**
 * The shared attached-document invariant, on its own: row first, bytes
 * second, bytes only when owned and unreferenced.
 *
 * Camps and Locations each learned this rule after a bug — a deleted
 * document blanking the inbound message whose attachment it was — and the
 * cases below are the ones those bugs were made of.
 */
class AttachedFileRemoverTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $files;
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
        $this->files = new FileRepository($this->pdo);

        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-attached-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/sub', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/sub/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/sub');
        @rmdir($this->storagePath);
    }

    public function testOwnedAndUnreferencedFileLosesItsRowAndItsBytes(): void
    {
        $fileId = $this->createFile('sub/contract.pdf');
        $repository = new RecordingAttachedFileRepository(false);

        (new AttachedFileRemover($this->files, $this->storagePath))
            ->remove($repository, 7, $fileId, true);

        $this->assertSame([7], $repository->deleted);
        $this->assertNull($this->files->findById($fileId));
        $this->assertFileDoesNotExist($this->storagePath . '/sub/contract.pdf');
    }

    public function testAttachmentTheModuleDoesNotOwnKeepsItsFile(): void
    {
        $fileId = $this->createFile('sub/inbound.pdf');
        $repository = new RecordingAttachedFileRepository(false);

        (new AttachedFileRemover($this->files, $this->storagePath))
            ->remove($repository, 7, $fileId, false);

        $this->assertSame([7], $repository->deleted, 'the link still goes');
        $this->assertNotNull($this->files->findById($fileId), 'the message still serves it');
        $this->assertFileExists($this->storagePath . '/sub/inbound.pdf');
        $this->assertSame(
            [],
            $repository->referenceChecks,
            'a file the module does not own is never even asked about'
        );
    }

    public function testOwnedFileStillReferencedElsewhereKeepsItsBytes(): void
    {
        $fileId = $this->createFile('sub/shared.pdf');
        $repository = new RecordingAttachedFileRepository(true);

        (new AttachedFileRemover($this->files, $this->storagePath))
            ->remove($repository, 7, $fileId, true);

        $this->assertSame([[$fileId, 7]], $repository->referenceChecks);
        $this->assertNotNull($this->files->findById($fileId));
        $this->assertFileExists($this->storagePath . '/sub/shared.pdf');
    }

    public function testMissingFileRowIsNotAnError(): void
    {
        $repository = new RecordingAttachedFileRepository(false);

        (new AttachedFileRemover($this->files, $this->storagePath))
            ->remove($repository, 7, 4242, true);

        $this->assertSame([7], $repository->deleted);
    }

    public function testWithoutAStoragePathOnlyTheDatabaseRowsGo(): void
    {
        $fileId = $this->createFile('sub/orphan.pdf');

        (new AttachedFileRemover($this->files, ''))
            ->remove(new RecordingAttachedFileRepository(false), 7, $fileId, true);

        $this->assertNull($this->files->findById($fileId));
        $this->assertFileExists($this->storagePath . '/sub/orphan.pdf');
    }

    private function createFile(string $relativePath): int
    {
        file_put_contents($this->storagePath . '/' . $relativePath, 'bytes');

        return $this->files->create($relativePath, 'doc.pdf', 'application/pdf', 5, 'identified', null, null);
    }
}

/** @internal */
class RecordingAttachedFileRepository implements AttachedFileRepository
{
    /** @var int[] */
    public array $deleted = [];

    /** @var array<int, array{0: int, 1: int}> */
    public array $referenceChecks = [];

    public function __construct(private bool $referencedElsewhere)
    {
    }

    public function delete(int $id): void
    {
        $this->deleted[] = $id;
    }

    public function isFileReferencedElsewhere(int $fileId, int $exceptDocumentId): bool
    {
        $this->referenceChecks[] = [$fileId, $exceptDocumentId];

        return $this->referencedElsewhere;
    }
}
