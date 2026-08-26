<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\PdfThumbnailCache;
use PHPUnit\Framework\TestCase;

/**
 * The pdf_thumb directory used to grow forever: one JPEG per PDF ever
 * previewed, deleted by nothing, orphaned when the PDF itself went.
 */
class PdfThumbnailCacheTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-pdf-thumb-' . bin2hex(random_bytes(4));
        mkdir($this->storagePath . '/' . PdfThumbnailCache::SUBDIRECTORY, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/' . PdfThumbnailCache::SUBDIRECTORY . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/' . PdfThumbnailCache::SUBDIRECTORY);
        @rmdir($this->storagePath . '/temp');
        @rmdir($this->storagePath);
    }

    public function testStaleThumbnailsAreDeletedAndFreshOnesKept(): void
    {
        $dir = $this->storagePath . '/' . PdfThumbnailCache::SUBDIRECTORY;
        file_put_contents($dir . '/1.jpg', 'x');
        file_put_contents($dir . '/2.jpg', 'x');
        touch($dir . '/1.jpg', time() - 31 * 86400);

        $this->assertSame(1, PdfThumbnailCache::purgeStale($this->storagePath));

        $this->assertFileDoesNotExist($dir . '/1.jpg');
        $this->assertFileExists($dir . '/2.jpg');
    }

    public function testAMissingDirectoryIsANoOp(): void
    {
        $this->assertSame(0, PdfThumbnailCache::purgeStale($this->storagePath . '/nowhere'));
    }

    public function testOnlyJpegCacheEntriesAreTouched(): void
    {
        $dir = $this->storagePath . '/' . PdfThumbnailCache::SUBDIRECTORY;
        file_put_contents($dir . '/note.txt', 'x');
        touch($dir . '/note.txt', time() - 90 * 86400);

        $this->assertSame(0, PdfThumbnailCache::purgeStale($this->storagePath));
        $this->assertFileExists($dir . '/note.txt');
    }
}
