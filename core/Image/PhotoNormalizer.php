<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Image;

/**
 * Turns a photograph, as a phone sends it, into the one form the site
 * keeps: a JPEG at most 2 400 px on its longest side, quality 85, upright,
 * and carrying **no metadata at all** — no EXIF, no GPS position, no camera
 * serial number.
 *
 * **It refuses rather than keeps.** `Core\File\UploadHandler` stores a file
 * it cannot re-encode as it came (an HEIC photo, an image GD fails to
 * decode), metadata included. That is acceptable for a scanned contract;
 * it is not for a photograph taken inside a rented hall, whose EXIF carries
 * the coordinates of the place and the time to the second. So every path
 * here either produces fresh pixels or throws a `PhotoNormalizationException`
 * with a French sentence: there is no "keep the original" branch to fall
 * into.
 *
 * **HEIC is read only where the server can.** GD does not decode it.
 * Imagick does when its ImageMagick was built with libheif, which
 * `Imagick::queryFormats('HEIC')` is the only reliable way to tell
 * (`forThisServer()`). Without it the photo is refused with the sentence
 * that tells an iPhone owner what to change; a browser that converts HEIC
 * before sending (Safari does, and iOS transcodes when the file input asks
 * for JPEG) never reaches that branch.
 *
 * Used before `UploadHandler::handle()`, on the model of
 * `Core\Photo\PhotoIngestionService` (process, then store): the file stored
 * and the size recorded are already the final ones.
 */
final class PhotoNormalizer
{
    /** Longest side of a stored photo, in pixels. */
    public const MAX_LONG_SIDE = 2400;

    /** JPEG quality of a stored photo. */
    public const JPEG_QUALITY = 85;

    /** What GD decodes directly. */
    private const GD_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** What a phone may send that only the HEIC converter reads. */
    private const HEIC_MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    public const HEIC_UNREADABLE = "Cette photo est au format HEIC, que ce serveur ne sait pas lire : réglez "
        . "l'appareil photo sur « Le plus compatible » ou choisissez une autre photo.";

    public const NOT_A_PHOTO = "Ce fichier n'est pas une photo que le site sait lire : choisissez une photo "
        . 'JPEG, PNG ou WebP.';

    public const UNREADABLE = "Cette photo n'a pas pu être lue : elle est peut-être abîmée. Choisissez-en "
        . 'une autre.';

    /**
     * @param (\Closure(string): string)|null $heicToJpeg Turns HEIC bytes
     *        into upright JPEG bytes. Null on a server that cannot read
     *        HEIC: such a photo is then refused, never kept as it came.
     */
    public function __construct(private ?\Closure $heicToJpeg = null)
    {
    }

    /**
     * The normaliser this server can offer: HEIC through Imagick when its
     * ImageMagick reads that format, refused otherwise.
     */
    public static function forThisServer(): self
    {
        return new self(self::serverReadsHeic() ? self::imagickToJpeg(...) : null);
    }

    public static function serverReadsHeic(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }

        try {
            return \Imagick::queryFormats('HEIC') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function readsHeic(): bool
    {
        return $this->heicToJpeg !== null;
    }

    /**
     * The photo, as the JPEG the site keeps.
     *
     * The bound and the quality default to what is stored; a caller that
     * needs a smaller copy of a stored photo for one use — the 1 600 px
     * JPEG an inventory's PDF embeds and never keeps (#989) — passes its
     * own, and gets the same decode, guard, orientation and fresh canvas.
     *
     * @throws PhotoNormalizationException
     */
    public function normalize(
        string $bytes,
        int $maxLongSide = self::MAX_LONG_SIDE,
        int $quality = self::JPEG_QUALITY
    ): string {
        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (in_array($mimeType, self::HEIC_MIMES, true)) {
            if ($this->heicToJpeg === null) {
                throw new PhotoNormalizationException(self::HEIC_UNREADABLE);
            }
            try {
                $bytes = ($this->heicToJpeg)($bytes);
            } catch (PhotoNormalizationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new PhotoNormalizationException(self::UNREADABLE, 0, $e);
            }
            $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        }

        if (!in_array($mimeType, self::GD_MIMES, true)) {
            throw new PhotoNormalizationException(self::NOT_A_PHOTO);
        }

        // A decompression bomb is refused before GD allocates for it
        // (SECURITY.md §25). The guard's own sentence is French and
        // written for the visitor, so it is passed on as it is.
        try {
            ImageDimensionGuard::assertWithinCeilingFromString($bytes);
        } catch (ImageDimensionException $e) {
            throw new PhotoNormalizationException($e->getMessage(), 0, $e);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new PhotoNormalizationException(self::UNREADABLE);
        }

        if ($mimeType === 'image/jpeg') {
            $image = self::upright($image, self::orientationOf($bytes));
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, max(1, $maxLongSide) / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // A fresh canvas every time, even at full size: the pixels are
        // copied onto it, nothing else is, and a transparent PNG lands on
        // white rather than on JPEG's black.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        ob_start();
        $written = imagejpeg($canvas, null, max(1, min(100, $quality)));
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);

        if (!$written || $jpeg === '') {
            throw new PhotoNormalizationException(self::UNREADABLE);
        }

        return $jpeg;
    }

    /**
     * An uploaded file (`$_FILES` entry) normalised into a temporary JPEG,
     * returned as an entry of the same shape that `UploadHandler::handle()`
     * then stores. The caller deletes the temporary file it points at
     * (`discard()`), stored or not.
     *
     * @param array<string, mixed> $uploadedFile
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     * @throws PhotoNormalizationException
     */
    public function normalizeUpload(array $uploadedFile, int $maxSizeBytes): array
    {
        $tmpName = $uploadedFile['tmp_name'] ?? '';
        $error = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK || !is_string($tmpName) || $tmpName === '' || !is_file($tmpName)) {
            throw new PhotoNormalizationException("La photo n'est pas arrivée : envoyez-la à nouveau.");
        }

        $size = (int) ($uploadedFile['size'] ?? filesize($tmpName));
        if ($size > $maxSizeBytes) {
            throw new PhotoNormalizationException(
                'La photo dépasse la taille maximale autorisée (' . round($maxSizeBytes / 1024 / 1024, 1) . ' Mo).'
            );
        }

        $jpeg = $this->normalize((string) file_get_contents($tmpName));

        $path = tempnam(sys_get_temp_dir(), 'photo_');
        if ($path === false || file_put_contents($path, $jpeg) === false) {
            throw new PhotoNormalizationException("La photo n'a pas pu être préparée : réessayez.");
        }

        $name = pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_FILENAME);

        return [
            'name' => ($name !== '' ? $name : 'photo') . '.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($jpeg),
        ];
    }

    /**
     * Deletes the temporary file of an entry normalizeUpload() returned.
     *
     * @param array<string, mixed> $normalized
     */
    public static function discard(array $normalized): void
    {
        $path = $normalized['tmp_name'] ?? '';
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * HEIC (or anything else ImageMagick reads) to an upright JPEG with no
     * metadata, under the resource ceilings `Core\File\PdfRasterizer`
     * uses, after a header-only read refused anything over the pixel
     * ceiling (SECURITY.md §25).
     *
     * @throws PhotoNormalizationException
     */
    public static function imagickToJpeg(string $bytes): string
    {
        $imagick = new \Imagick();
        $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        $imagick->setResourceLimit(\Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);

        $imagick->pingImageBlob($bytes);
        $pixels = $imagick->getImageWidth() * $imagick->getImageHeight();
        if ($pixels > ImageDimensionGuard::MAX_PIXELS) {
            throw new PhotoNormalizationException(sprintf(
                'Image trop grande (%d×%d pixels) : la limite est de %d mégapixels.',
                $imagick->getImageWidth(),
                $imagick->getImageHeight(),
                intdiv(ImageDimensionGuard::MAX_PIXELS, 1_000_000)
            ));
        }
        $imagick->clear();

        $imagick->readImageBlob($bytes);
        $imagick->setIteratorIndex(0);
        $imagick->autoOrient();
        $imagick->stripImage();
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(95);
        $jpeg = $imagick->getImageBlob();
        $imagick->clear();

        return $jpeg;
    }

    /** The EXIF orientation of JPEG bytes, 1 when there is none. */
    private static function orientationOf(string $bytes): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return 1;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Bakes an EXIF orientation into the pixels, the eight values
     * included: the tag that would have told a viewer to turn the photo
     * goes with the rest of the metadata.
     */
    private static function upright(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        // GD turns counter-clockwise: -90 is a quarter turn clockwise.
        $angle = match ($orientation) {
            3, 4 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
