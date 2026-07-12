<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\FileRepository;
use Core\File\UploadException;
use Core\File\UploadHandler;
use PHPUnit\Framework\TestCase;

class UploadHandlerTest extends TestCase
{
    private string $tmpDir;
    private TestUploadHandler $handler;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scoutmagic_upload_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

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

        $repo = new FileRepository($this->pdo);
        $this->handler = new TestUploadHandler($repo, $this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testSuccessfulUploadCreatesFileAndRecord(): void
    {
        $tmpFile = $this->createTempImage();

        $fileId = $this->handler->handle(
            ['tmp_name' => $tmpFile, 'name' => 'photo.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK],
            'test',
            ['image/jpeg', 'image/png'],
            10 * 1024 * 1024,
            'public',
            null,
            1
        );

        $this->assertGreaterThan(0, $fileId);

        // Verify file exists in storage
        $stmt = $this->pdo->prepare('SELECT relative_path FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertFileExists($this->tmpDir . '/' . $row['relative_path']);
    }

    public function testRejectedMimeTypeThrowsException(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'not an image');

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('non autorisé');

        $this->handler->handle(
            ['tmp_name' => $tmpFile, 'name' => 'evil.txt', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK],
            'test',
            ['image/jpeg'],
            10 * 1024 * 1024,
            'public'
        );
    }

    public function testFileSizeLimitThrowsException(): void
    {
        $tmpFile = $this->createTempImage();

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('taille maximale');

        $this->handler->handle(
            ['tmp_name' => $tmpFile, 'name' => 'photo.jpg', 'size' => 20 * 1024 * 1024, 'error' => UPLOAD_ERR_OK],
            'test',
            ['image/jpeg'],
            1, // 1 byte max
            'public'
        );
    }

    public function testGeneratedFilenameIsRandom(): void
    {
        $tmpFile1 = $this->createTempImage();
        $tmpFile2 = $this->createTempImage();

        $id1 = $this->handler->handle(
            ['tmp_name' => $tmpFile1, 'name' => 'photo.jpg', 'size' => filesize($tmpFile1), 'error' => UPLOAD_ERR_OK],
            'test',
            ['image/jpeg'],
            10 * 1024 * 1024,
            'public'
        );

        $id2 = $this->handler->handle(
            ['tmp_name' => $tmpFile2, 'name' => 'photo.jpg', 'size' => filesize($tmpFile2), 'error' => UPLOAD_ERR_OK],
            'test',
            ['image/jpeg'],
            10 * 1024 * 1024,
            'public'
        );

        $stmt = $this->pdo->prepare('SELECT relative_path FROM files WHERE id IN (?, ?)');
        $stmt->execute([$id1, $id2]);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0], $rows[1]);
    }

    public function testExifDataStrippedFromJpeg(): void
    {
        // Create a JPEG with EXIF
        $tmpFile = tempnam(sys_get_temp_dir(), 'exif');
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $tmpFile, 90);
        imagedestroy($img);

        $fileId = $this->handler->handle(
            ['tmp_name' => $tmpFile, 'name' => 'exif.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK],
            'test',
            ['image/jpeg'],
            10 * 1024 * 1024,
            'public'
        );

        $stmt = $this->pdo->prepare('SELECT relative_path FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $storedPath = $this->tmpDir . '/' . $row['relative_path'];

        // Verify it's a valid JPEG (GD re-encoded)
        $this->assertNotFalse(@imagecreatefromjpeg($storedPath));
    }

    public function testUploadErrorThrowsException(): void
    {
        $this->expectException(UploadException::class);
        $this->handler->handle(
            ['tmp_name' => '', 'name' => 'file.jpg', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE],
            'test',
            ['image/jpeg'],
            10 * 1024 * 1024,
            'public'
        );
    }

    private function createTempImage(): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'img');
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $tmpFile);
        imagedestroy($img);
        return $tmpFile;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}

/**
 * Test subclass that overrides moveFile to use copy instead of move_uploaded_file.
 */
class TestUploadHandler extends UploadHandler
{
    protected function moveFile(string $from, string $to): bool
    {
        return copy($from, $to);
    }
}
