<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Repository\Media;
use Modules\Gallery\Service\MediaFileName;
use PHPUnit\Framework\TestCase;

/**
 * What a downloaded media is called — the one rule the album's zip and a
 * single photo saved from the lightbox both ask (Service\MediaFileName).
 */
class MediaFileNameTest extends TestCase
{
    public function testItKeepsTheOriginalNameAndAppendsTheMediaId(): void
    {
        $this->assertSame('IMG_1234_42.jpg', MediaFileName::forMedia($this->photo(42, 'IMG_1234.JPG')));
    }

    /**
     * The reason the id is in the name at all: two phones both call their
     * photo IMG_1234, and somebody saving them one at a time has no
     * archive around them to disambiguate — the second would silently
     * overwrite the first in their downloads folder.
     */
    public function testTwoMediaSharingAnOriginalNameStillDownloadAsTwoFiles(): void
    {
        $first = MediaFileName::forMedia($this->photo(7, 'IMG_1234.jpg'));
        $second = MediaFileName::forMedia($this->photo(8, 'IMG_1234.jpg'));

        $this->assertNotSame($first, $second);
        $this->assertSame(['IMG_1234_7.jpg', 'IMG_1234_8.jpg'], [$first, $second]);
    }

    public function testAMediaWithNoOriginalNameIsStillNamed(): void
    {
        $this->assertSame('media_42.jpg', MediaFileName::forMedia($this->photo(42, null)));
    }

    /**
     * The string lands in a Content-Disposition header and in a zip
     * entry; neither is a good place to discover a phone named a photo in
     * Cyrillic, or with a path separator in it.
     */
    public function testAnythingOutsideAsciiIsFlattened(): void
    {
        // Nothing usable survives, so the name falls back rather than
        // becoming a run of underscores.
        $this->assertSame('media_42.jpg', MediaFileName::forMedia($this->photo(42, 'Фото.jpg')));
        // Directories never survive either: pathinfo() takes the base
        // name, so nothing a caller writes can climb out of a zip entry
        // or out of a download folder.
        $this->assertSame('passwd_42.jpg', MediaFileName::forMedia($this->photo(42, '../../etc/passwd.jpg')));
        $this->assertSame('photo_de_camp_42.jpg', MediaFileName::forMedia($this->photo(42, 'photo de camp.jpg')));
    }

    public function testAPathologicalNameIsBounded(): void
    {
        $name = MediaFileName::forMedia($this->photo(42, str_repeat('a', 500) . '.jpg'));

        $this->assertSame(str_repeat('a', 60) . '_42.jpg', $name);
    }

    /**
     * The extension follows the bytes actually handed out, not the
     * upload: every derived photo is a JPEG and every derived video an
     * MP4, so a `.heic` original downloads as `.jpg`.
     */
    public function testTheExtensionFollowsTheRenditionRatherThanTheUpload(): void
    {
        $this->assertSame('vue_1.jpg', MediaFileName::forMedia($this->photo(1, 'vue.heic')));
        $this->assertSame('clip_2.mp4', MediaFileName::forMedia($this->video(2, 'clip.mov')));
    }

    private function photo(int $id, ?string $originalFilename): Media
    {
        return $this->media($id, 'photo', $originalFilename);
    }

    private function video(int $id, ?string $originalFilename): Media
    {
        return $this->media($id, 'video', $originalFilename);
    }

    private function media(int $id, string $type, ?string $originalFilename): Media
    {
        return new Media(
            id: $id,
            albumId: 1,
            mediaType: $type,
            fileId: 1,
            thumbPath: 'thumb.jpg',
            mediumPath: 'med.jpg',
            largePath: 'lg.jpg',
            originalPath: null,
            processingStatus: Media::STATUS_DONE,
            width: 100,
            height: 100,
            durationSeconds: null,
            sortOrder: 0,
            originalFilename: $originalFilename,
            createdAt: '2026-01-01 10:00:00'
        );
    }
}
