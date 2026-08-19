<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * GD-based photo resize (no external binary — unlike video, this always
 * works). Produces three JPEG sizes from a source file: thumb (300px long
 * side), medium (1200px long side), large (capped at $maxDimension). Every
 * target below is a LONG SIDE, never a width: a portrait photo and a
 * landscape one of the same pixel count must come out the same weight.
 */
class ImageProcessingService
{
    private const THUMB_LONG_SIDE = 300;
    private const MEDIUM_LONG_SIDE = 1200;
    private const THUMB_QUALITY = 80;
    private const MEDIUM_QUALITY = 85;
    private const LARGE_QUALITY = 90;

    /**
     * @return array{thumb: string, medium: string, large: string, width: int, height: int}
     * @throws GalleryException when the source can't be decoded or encoded
     */
    public function process(string $contents, string $mimeType, int $maxDimension): array
    {
        $image = $this->decode($contents, $mimeType);
        $image = $this->correctOrientation($image, $contents, $mimeType);

        $width = imagesx($image);
        $height = imagesy($image);
        $longSide = max($width, $height);

        // gallery_photo_max_dimension is a free-form number setting; a zero
        // or negative value would ask GD for a 0x0 canvas (a fatal, and
        // every photo in the album stuck on 'failed'). Fall back to the
        // source's own size, i.e. "no capping".
        $largeTarget = $maxDimension > 0 ? min($maxDimension, $longSide) : $longSide;
        // "medium" is always a downscale of "large", never larger than it:
        // without this cap, an admin setting the max dimension below 1200
        // produced a medium rendition BIGGER than the large one, so the
        // lightbox's "Voir en haute qualité" button downgraded the image.
        $mediumTarget = min(self::MEDIUM_LONG_SIDE, $largeTarget);

        $large = $this->resizeAndEncode($image, $largeTarget, self::LARGE_QUALITY);
        // Compared on the long side, not the width: a tall narrow photo (a
        // phone screenshot, a portrait shot) is not "already medium-sized"
        // just because it happens to be under 1200px WIDE — that bug served
        // the full-size image as the grid's medium rendition.
        $medium = $mediumTarget >= $largeTarget
            ? $large
            : $this->resizeAndEncode($image, $mediumTarget, self::MEDIUM_QUALITY);
        $thumb = $this->resizeAndEncode($image, self::THUMB_LONG_SIDE, self::THUMB_QUALITY);

        imagedestroy($image);

        return ['thumb' => $thumb, 'medium' => $medium, 'large' => $large, 'width' => $width, 'height' => $height];
    }

    /**
     * @return \GdImage
     */
    private function decode(string $contents, string $mimeType)
    {
        // imagecreatefromstring() sniffs the format itself; the match is
        // only here to refuse anything outside the album's allowed photo
        // mime types (Service\MediaService::PHOTO_MIMES).
        $image = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            ? @imagecreatefromstring($contents)
            : false;

        if ($image === false) {
            throw new GalleryException('Impossible de décoder cette image.');
        }

        return $image;
    }

    /**
     * Fallback only: Core\File\UploadHandler already bakes EXIF orientation
     * into the pixels and strips the tag before the original ever reaches
     * this service, so there is normally nothing left to correct here. It
     * still matters for the one path where that didn't happen — GD failing
     * to re-encode the upload, in which case UploadHandler stores the file
     * verbatim, EXIF and all.
     *
     * @param \GdImage $image
     * @return \GdImage
     */
    private function correctOrientation($image, string $contents, string $mimeType)
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));
        $orientation = $exif['Orientation'] ?? 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };
        if ($rotated === null || $rotated === false) {
            return $image;
        }

        // imagerotate() returns a NEW image — the pre-rotation one is dead
        // from here on and has to be released explicitly, or every rotated
        // photo leaks a full uncompressed bitmap for the rest of the task.
        imagedestroy($image);

        return $rotated;
    }

    /**
     * @param \GdImage $image
     * @throws GalleryException when GD can't allocate or encode the target
     */
    private function resizeAndEncode($image, int $targetLongSide, int $quality): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longSide = max($width, $height);

        if ($longSide <= $targetLongSide) {
            $targetWidth = $width;
            $targetHeight = $height;
        } else {
            $scale = $targetLongSide / $longSide;
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
        }

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) {
            throw new GalleryException('Impossible de redimensionner cette image.');
        }
        // Flatten any transparency onto white — the output is always JPEG.
        $white = imagecolorallocate($resized, 255, 255, 255);
        if ($white !== false) {
            imagefill($resized, 0, 0, $white);
        }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $encoded = imagejpeg($resized, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($resized);

        if (!$encoded || $bytes === '') {
            throw new GalleryException('Impossible d\'encoder cette image.');
        }

        return $bytes;
    }
}
