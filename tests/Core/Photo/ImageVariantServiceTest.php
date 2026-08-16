<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\FileRepository;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use PHPUnit\Framework\TestCase;

class ImageVariantServiceTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $fileRepository;
    private ImageVariantService $service;
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

        $this->fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/image_variant_service_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);
        $this->service = new ImageVariantService($this->fileRepository, new ImageVariantProcessor(), $this->storagePath);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->storagePath);
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function storeOriginal(string $relativePath, string $contents, bool $encrypted = false): int
    {
        $dir = dirname($this->storagePath . '/' . $relativePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->storagePath . '/' . $relativePath, $contents);

        return $this->fileRepository->create($relativePath, 'photo.jpg', 'image/jpeg', strlen($contents), 'public', null, null, $encrypted);
    }

    public function testGenerateWritesAThumbSiblingNextToTheOriginal(): void
    {
        $fileId = $this->storeOriginal('core/member_photos/abc123.jpg', $this->jpegBytes(800, 600));

        $this->service->generate($fileId, 'thumb');

        $this->assertFileExists($this->storagePath . '/core/member_photos/abc123.thumb.webp');
    }

    public function testGenerateWritesAnMdSiblingNextToTheOriginal(): void
    {
        $fileId = $this->storeOriginal('core/section_photos/xyz789.jpg', $this->jpegBytes(800, 600));

        $this->service->generate($fileId, 'md');

        $this->assertFileExists($this->storagePath . '/core/section_photos/xyz789.md.webp');
    }

    public function testResolvePathReturnsTheGeneratedDerivative(): void
    {
        $fileId = $this->storeOriginal('core/member_photos/abc123.jpg', $this->jpegBytes(800, 600));
        $this->service->generate($fileId, 'thumb');
        $file = $this->fileRepository->findById($fileId);

        $path = $this->service->resolvePath($file->relativePath, 'thumb');

        $this->assertNotNull($path);
        $this->assertFileExists($path);
    }

    public function testResolvePathReturnsNullWhenNoDerivativeWasGenerated(): void
    {
        $fileId = $this->storeOriginal('core/member_photos/abc123.jpg', $this->jpegBytes(800, 600));
        $file = $this->fileRepository->findById($fileId);

        $this->assertNull($this->service->resolvePath($file->relativePath, 'thumb'));
    }

    public function testResolvePathReturnsNullForAnUnknownVariant(): void
    {
        $fileId = $this->storeOriginal('core/member_photos/abc123.jpg', $this->jpegBytes(800, 600));
        $this->service->generate($fileId, 'thumb');
        $file = $this->fileRepository->findById($fileId);

        $this->assertNull($this->service->resolvePath($file->relativePath, 'large'));
    }

    /**
     * Never interpolate the raw request segment into a filesystem path —
     * a path-traversal attempt in $variant must be rejected by the fixed
     * vocabulary check before any path is even built.
     */
    public function testResolvePathRejectsAPathTraversalVariant(): void
    {
        $fileId = $this->storeOriginal('core/member_photos/abc123.jpg', $this->jpegBytes(800, 600));
        $file = $this->fileRepository->findById($fileId);

        $this->assertNull($this->service->resolvePath($file->relativePath, '../../../etc/passwd'));
    }

    public function testGenerateIsANoOpForAnUnknownFileId(): void
    {
        $this->service->generate(999999, 'thumb');
        // No exception, nothing written — nothing to assert on disk since
        // there is no relative path to derive a sibling from.
        $this->assertTrue(true);
    }

    public function testGenerateIsANoOpForAnEncryptedFile(): void
    {
        $fileId = $this->storeOriginal('finance/receipts/enc123.jpg', $this->jpegBytes(400, 300), true);

        $this->service->generate($fileId, 'thumb');

        $this->assertFileDoesNotExist($this->storagePath . '/finance/receipts/enc123.thumb.webp');
    }

    public function testGenerateIsANoOpForAnUndecodableSource(): void
    {
        $fileId = $this->storeOriginal('core/member_photos/broken.jpg', 'not an image');

        $this->service->generate($fileId, 'thumb');

        $this->assertFileDoesNotExist($this->storagePath . '/core/member_photos/broken.thumb.webp');
    }

    public function testIsValidVariantAcceptsOnlyTheFixedVocabulary(): void
    {
        $this->assertTrue(ImageVariantService::isValidVariant('thumb'));
        $this->assertTrue(ImageVariantService::isValidVariant('md'));
        $this->assertFalse(ImageVariantService::isValidVariant('large'));
        $this->assertFalse(ImageVariantService::isValidVariant(''));
        $this->assertFalse(ImageVariantService::isValidVariant('../../etc/passwd'));
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
