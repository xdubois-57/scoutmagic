<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Pdf;

use Core\File\FileRepository;
use Core\Photo\ImageVariantService;
use Core\Photo\MemberPhotoService;

/**
 * Turns a member into a small, embeddable JPEG.
 *
 * dompdf runs with `isRemoteEnabled = false` — deliberately, since a PDF
 * generator that fetches URLs is a server-side request forgery waiting to
 * happen — so every photo has to be carried inside the HTML as a data URI.
 * Two consequences the whole class exists for:
 *
 * 1. **Size.** An original portrait is a megabyte or two. Thirty of them
 *    inlined would be a hundred megabytes of base64 in one string, on
 *    shared hosting. What is embedded here is a {@see self::SIDE}px square
 *    at {@see self::QUALITY} quality — a few kilobytes each.
 * 2. **Format.** `Core\Photo\ImageVariantService`'s `thumb` derivative is
 *    already the right SHAPE (192px, centre-cropped square, generated once
 *    at upload) but it is WebP, which dompdf cannot decode. It is read
 *    here as a source and re-encoded as JPEG.
 *
 * A member with no photo, or whose photo cannot be read or decoded, simply
 * gets null: the document draws the same initials-in-a-disc avatar it
 * draws on screen. A stale file row must never fail a whole document.
 */
class StaffPhotoEmbedder
{
    /** Square side of the embedded JPEG, in pixels. */
    private const SIDE = 150;

    private const QUALITY = 72;

    /**
     * Resolutions for the lifetime of this instance, misses included. A
     * section's responsable is drawn twice — once on the directory page,
     * once on their section's page — and decoding their portrait twice
     * would be pure waste.
     *
     * @var array<int, ?string>
     */
    private array $cache = [];

    public function __construct(
        private MemberPhotoService $memberPhotoService,
        private FileRepository $fileRepository,
        private ImageVariantService $imageVariantService,
        private string $storagePath
    ) {
    }

    /**
     * Resolve every portrait's file id in one query before dataUriFor() is
     * asked member by member.
     *
     * @param array<int, int> $memberIds
     */
    public function prime(array $memberIds, int $scoutYearId): void
    {
        $this->memberPhotoService->primeFileIds($memberIds, $scoutYearId);
    }

    /**
     * The file id behind a member's portrait, or null — what the PDF's
     * cache signature is built from, without decoding a single image.
     */
    public function fileIdFor(int $memberId, int $scoutYearId): ?int
    {
        return $this->memberPhotoService->resolveFileId($memberId, $scoutYearId);
    }

    /**
     * @return ?string `data:image/jpeg;base64,…`, or null when this member
     *         has no usable photo for that scout year.
     */
    public function dataUriFor(int $memberId, int $scoutYearId): ?string
    {
        if (!array_key_exists($memberId, $this->cache)) {
            $this->cache[$memberId] = $this->buildDataUri($memberId, $scoutYearId);
        }

        return $this->cache[$memberId];
    }

    private function buildDataUri(int $memberId, int $scoutYearId): ?string
    {
        $fileId = $this->memberPhotoService->resolveFileId($memberId, $scoutYearId);
        if ($fileId === null) {
            return null;
        }

        $file = $this->fileRepository->findById($fileId);
        // No core photo context is ever encrypted; refusing one rather than
        // reaching for the master key keeps this class out of that path.
        if ($file === null || $file->encrypted) {
            return null;
        }

        // Prefer the square 192px derivative — already cropped, already
        // small — and fall back to the original for a photo uploaded
        // before that pipeline existed (ImageVariantService never
        // generates on demand).
        $path = $this->imageVariantService->resolvePath($file->relativePath, 'thumb')
            ?? $this->storagePath . '/' . $file->relativePath;
        if (!is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $jpeg = $this->toSquareJpeg($bytes);

        return $jpeg !== null ? 'data:image/jpeg;base64,' . base64_encode($jpeg) : null;
    }

    /**
     * Decode, centre-crop to a square, resize, flatten onto white, encode
     * as JPEG. The flattening matters: the `thumb` derivative preserves
     * alpha, and JPEG has none — without an explicit white ground a
     * transparent corner comes out black.
     */
    private function toSquareJpeg(string $bytes): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $crop = min($width, $height);

        $canvas = imagecreatetruecolor(self::SIDE, self::SIDE);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, self::SIDE, self::SIDE, $white);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            (int) round(($width - $crop) / 2),
            (int) round(($height - $crop) / 2),
            self::SIDE,
            self::SIDE,
            $crop,
            $crop
        );
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $encoded = ob_get_clean();
        imagedestroy($canvas);

        return $encoded === false || $encoded === '' ? null : $encoded;
    }
}
