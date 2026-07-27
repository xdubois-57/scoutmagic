<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service\Storage;

use Modules\Gallery\Service\Storage\LocalStorageBackend;
use PHPUnit\Framework\TestCase;

class LocalStorageBackendTest extends TestCase
{
    private string $storagePath;
    private LocalStorageBackend $backend;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/gallery_test_' . uniqid();
        $this->backend = new LocalStorageBackend($this->storagePath, 'gallery');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeRecursively($this->storagePath);
        }
    }

    private function removeRecursively(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }

    public function testPutThenGetRoundTrips(): void
    {
        $this->backend->put('1/thumb_1.jpg', 'fake-jpeg-bytes', 'image/jpeg');

        $this->assertSame('fake-jpeg-bytes', $this->backend->get('1/thumb_1.jpg'));
    }

    public function testExistsReflectsPresence(): void
    {
        $this->assertFalse($this->backend->exists('1/thumb_1.jpg'));

        $this->backend->put('1/thumb_1.jpg', 'data', 'image/jpeg');

        $this->assertTrue($this->backend->exists('1/thumb_1.jpg'));
    }

    public function testDeleteRemovesTheFile(): void
    {
        $this->backend->put('1/thumb_1.jpg', 'data', 'image/jpeg');

        $this->backend->delete('1/thumb_1.jpg');

        $this->assertFalse($this->backend->exists('1/thumb_1.jpg'));
    }

    public function testGetThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->get('missing/nope.jpg');
    }

    public function testDeletePrefixRemovesTheWholeAlbumDirectory(): void
    {
        $this->backend->put('7/thumb_1.jpg', 'a', 'image/jpeg');
        $this->backend->put('7/med_1.jpg', 'b', 'image/jpeg');
        $this->backend->put('8/thumb_2.jpg', 'c', 'image/jpeg');

        $this->backend->deletePrefix('7');

        $this->assertFalse($this->backend->exists('7/thumb_1.jpg'));
        $this->assertFalse($this->backend->exists('7/med_1.jpg'));
        $this->assertTrue($this->backend->exists('8/thumb_2.jpg'));
    }

    public function testDeletePrefixOnMissingDirectoryIsANoOp(): void
    {
        $this->backend->deletePrefix('never-existed');
        $this->assertTrue(true);
    }
}
