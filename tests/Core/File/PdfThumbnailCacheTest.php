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
        $stale = PdfThumbnailCache::pathFor($this->storagePath, 1);
        $fresh = PdfThumbnailCache::pathFor($this->storagePath, 2);
        file_put_contents($stale, 'x');
        file_put_contents($fresh, 'x');
        touch($stale, time() - 31 * 86400);

        $this->assertSame(1, PdfThumbnailCache::purgeStale($this->storagePath));

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
    }

    /**
     * A thumbnail of an older rendering goes at once, however recent: it
     * is never served again (#1342 — the renders before the fix could be
     * black), and it would otherwise sit there for RETENTION_DAYS.
     */
    public function testAnOlderRenderingIsDeletedWhateverItsAge(): void
    {
        $dir = $this->storagePath . '/' . PdfThumbnailCache::SUBDIRECTORY;
        file_put_contents($dir . '/3.jpg', 'x');
        file_put_contents($dir . '/4.v1.jpg', 'x');
        file_put_contents(PdfThumbnailCache::pathFor($this->storagePath, 5), 'x');

        $this->assertSame(2, PdfThumbnailCache::purgeStale($this->storagePath));

        $this->assertFileDoesNotExist($dir . '/3.jpg');
        $this->assertFileDoesNotExist($dir . '/4.v1.jpg');
        $this->assertFileExists(PdfThumbnailCache::pathFor($this->storagePath, 5));
    }

    public function testTheCachedPathNamesTheRendering(): void
    {
        $this->assertSame(
            '/s/' . PdfThumbnailCache::SUBDIRECTORY . '/7.v' . PdfThumbnailCache::RENDER_VERSION . '.jpg',
            PdfThumbnailCache::pathFor('/s', 7)
        );
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
