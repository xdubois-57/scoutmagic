<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Modules\Gallery\Service\OgImageStore;
use PHPUnit\Framework\TestCase;

/**
 * An external album's image goes through the upload handler like any
 * upload — image types only, identified members only, under the album's
 * folder — and a refused image costs the album its picture, nothing else.
 */
final class OgImageStoreTest extends TestCase
{
    public function testTheImageIsStoredAsAnUploadOfTheAlbum(): void
    {
        $uploads = $this->createMock(UploadHandler::class);
        $uploads->expects($this->once())->method('handle')
            ->willReturnCallback(function (
                array $file,
                string $directory,
                array $mimes,
                int $max,
                string $role,
                ?string $module,
                ?int $by
            ): int {
                $this->assertSame('image-bytes', file_get_contents((string) $file['tmp_name']));
                $this->assertSame(11, $file['size']);
                $this->assertSame('gallery/12/og', $directory);
                $this->assertSame(OgImageStore::ALLOWED_MIMES, $mimes);
                $this->assertSame(OgImageStore::MAX_BYTES, $max);
                $this->assertSame('identified', $role);
                $this->assertSame('gallery', $module);
                $this->assertSame(3, $by);

                return 99;
            });

        $this->assertSame(99, (new OgImageStore($uploads))->store(12, 'image-bytes', 3));
    }

    /**
     * Issue #1259: an importer names its own working directory, which its
     * purge sweeps, so that a request killed before the `finally` does not
     * leave the copy in the system's temporary directory.
     */
    public function testTheCopyGoesInTheDirectoryTheCallerNames(): void
    {
        $directory = sys_get_temp_dir() . '/sm_og_' . bin2hex(random_bytes(6));
        $seen = null;
        $uploads = $this->createStub(UploadHandler::class);
        $uploads->method('handle')->willReturnCallback(static function (array $file) use (&$seen): int {
            $seen = (string) $file['tmp_name'];

            return 5;
        });

        try {
            $this->assertSame(5, (new OgImageStore($uploads))->store(12, 'image-bytes', 3, $directory));
            $this->assertSame(realpath($directory), dirname((string) $seen));
            $this->assertFileDoesNotExist((string) $seen, 'the copy is removed once stored');
        } finally {
            @rmdir($directory);
        }
    }

    public function testARefusedImageIsNoImage(): void
    {
        $uploads = $this->createStub(UploadHandler::class);
        $uploads->method('handle')->willThrowException(new UploadException('Type de fichier non autorisé.'));

        $this->assertNull((new OgImageStore($uploads))->store(12, 'pas une image', 3));
    }

    public function testNoBytesIsNoImage(): void
    {
        $uploads = $this->createMock(UploadHandler::class);
        $uploads->expects($this->never())->method('handle');

        $this->assertNull((new OgImageStore($uploads))->store(12, '', 3));
    }
}
