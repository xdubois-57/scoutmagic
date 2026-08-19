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

    public function testDeletePrefixRefusesAnEmptyPrefix(): void
    {
        $this->backend->put('7/thumb_1.jpg', 'a', 'image/jpeg');

        $this->backend->deletePrefix('');
        $this->backend->deletePrefix('/');

        // An empty prefix resolves to the location's own root — wiping every
        // album in it, not one.
        $this->assertTrue($this->backend->exists('7/thumb_1.jpg'));
    }

    public function testSizeReportsTheByteCount(): void
    {
        $this->backend->put('1/med_1.jpg', 'abcdefghij', 'image/jpeg');

        $this->assertSame(10, $this->backend->size('1/med_1.jpg'));
    }

    public function testSizeIsNullForAMissingKey(): void
    {
        $this->assertNull($this->backend->size('1/nope.jpg'));
    }

    public function testGetRangeReturnsOnlyTheRequestedSlice(): void
    {
        $this->backend->put('1/med_1.mp4', '0123456789', 'video/mp4');

        $this->assertSame('234', $this->backend->getRange('1/med_1.mp4', 2, 3));
        $this->assertSame('0', $this->backend->getRange('1/med_1.mp4', 0, 1));
    }

    public function testGetRangeClipsAtTheEndOfTheObject(): void
    {
        $this->backend->put('1/med_1.mp4', '0123456789', 'video/mp4');

        $this->assertSame('89', $this->backend->getRange('1/med_1.mp4', 8, 500));
    }

    public function testGetRangeOfZeroLengthIsEmpty(): void
    {
        $this->backend->put('1/med_1.mp4', '0123456789', 'video/mp4');

        $this->assertSame('', $this->backend->getRange('1/med_1.mp4', 0, 0));
    }

    public function testGetRangeThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->getRange('missing/nope.mp4', 0, 10);
    }

    /**
     * @dataProvider escapingKeys
     */
    public function testAKeyThatEscapesTheLocationIsRefused(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->put($key, 'payload', 'image/jpeg');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingKeys(): array
    {
        return [
            'parent hop' => ['../escaped.jpg'],
            'deep parent hop' => ['1/../../escaped.jpg'],
            'walks into the webroot' => ['../../public/escaped.jpg'],
            'backslash parent hop' => ['..\\escaped.jpg'],
        ];
    }

    public function testAnEscapingKeyIsAlsoRefusedOnRead(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->get('../../etc/passwd');
    }

    public function testADotSegmentInsideTheLocationStillResolves(): void
    {
        // "./" and a normalizing ".." that stays inside are fine — only
        // escaping the location's own directory is refused.
        $this->backend->put('9/./thumb_1.jpg', 'ok', 'image/jpeg');

        $this->assertSame('ok', $this->backend->get('9/thumb_1.jpg'));
    }
}
