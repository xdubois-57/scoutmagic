<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\UploadException;

/**
 * GD-based derivative generation for Core\Photo\ImageVariantService's two
 * fixed sizes — same before-storage GD precedent as SectionPhotoProcessor,
 * but runs AFTER the original is stored rather than before: by the time
 * ImageVariantService calls this, Core\File\UploadHandler (or, for
 * section_photo/editable_image, their own dedicated processor) has already
 * baked EXIF orientation into the pixels and stripped the EXIF block, so
 * there is nothing left to correct here — this class only decodes, then
 * crops/resizes, then re-encodes as WebP.
 *
 * `thumb` (192×192, centre-cropped square) is member_photo()'s avatar —
 * 192 covers the 96px `.member-photo` box at 2× exactly (public/assets/
 * css/components.css). `md` (1024px longest edge, aspect preserved) is
 * every other core photo context (section_photo, editable_image,
 * age_branch_logo). Alpha is preserved on both — a source PNG with
 * transparency must not end up flattened onto an arbitrary color, unlike
 * SectionPhotoProcessor's always-JPEG output.
 */
class ImageVariantProcessor
{
    private const THUMB_SIZE = 192;
    private const THUMB_QUALITY = 70;
    private const MD_MAX_DIMENSION = 1024;
    private const MD_QUALITY = 75;

    /**
     * @throws UploadException when the source can't be decoded, or $variant
     *     isn't one of the two known variant names
     */
    public function process(string $variant, string $contents, string $mimeType): string
    {
        return match ($variant) {
            'thumb' => $this->processThumb($contents, $mimeType),
            'md' => $this->processMd($contents, $mimeType),
            default => throw new UploadException('Variante d\'image inconnue.'),
        };
    }

    private function processThumb(string $contents, string $mimeType): string
    {
        $image = $this->decode($contents, $mimeType);
        $width = imagesx($image);
        $height = imagesy($image);
        $cropSize = min($width, $height);
        $sx = (int) round(($width - $cropSize) / 2);
        $sy = (int) round(($height - $cropSize) / 2);

        $resized = $this->newCanvas(self::THUMB_SIZE, self::THUMB_SIZE);
        imagecopyresampled($resized, $image, 0, 0, $sx, $sy, self::THUMB_SIZE, self::THUMB_SIZE, $cropSize, $cropSize);
        imagedestroy($image);

        return $this->encodeWebp($resized, self::THUMB_QUALITY);
    }

    private function processMd(string $contents, string $mimeType): string
    {
        $image = $this->decode($contents, $mimeType);
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= self::MD_MAX_DIMENSION) {
            $targetWidth = $width;
            $targetHeight = $height;
        } elseif ($width >= $height) {
            $targetWidth = self::MD_MAX_DIMENSION;
            $targetHeight = max(1, (int) round($height * self::MD_MAX_DIMENSION / $width));
        } else {
            $targetHeight = self::MD_MAX_DIMENSION;
            $targetWidth = max(1, (int) round($width * self::MD_MAX_DIMENSION / $height));
        }

        $resized = $this->newCanvas($targetWidth, $targetHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $this->encodeWebp($resized, self::MD_QUALITY);
    }

    /**
     * @return \GdImage
     */
    private function decode(string $contents, string $mimeType)
    {
        // Reject a decompression bomb before GD allocates for it (audit M7).
        try {
            \Core\Image\ImageDimensionGuard::assertWithinCeilingFromString($contents);
        } catch (\Core\Image\ImageDimensionException $e) {
            throw new UploadException($e->getMessage(), 0, $e);
        }

        $image = match ($mimeType) {
            'image/jpeg', 'image/png', 'image/webp', 'image/gif' => @imagecreatefromstring($contents),
            default => false,
        };

        if ($image === false) {
            throw new UploadException('Impossible de décoder cette image.');
        }

        return $image;
    }

    /**
     * A transparent truecolor canvas — alpha is preserved end to end
     * (unlike SectionPhotoProcessor, which always flattens to JPEG on
     * white) since these derivatives are always encoded as WebP, which
     * supports alpha natively.
     *
     * @return \GdImage
     */
    private function newCanvas(int $width, int $height)
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagealphablending($canvas, true);

        return $canvas;
    }

    /**
     * @param \GdImage $image
     */
    private function encodeWebp($image, int $quality): string
    {
        ob_start();
        imagewebp($image, null, $quality);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $encoded;
    }
}
