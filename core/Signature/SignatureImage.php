<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

use Core\Image\ImageDimensionException;
use Core\Image\ImageDimensionGuard;

/**
 * A signature as the site keeps and applies it (issue #926, moved from
 * `Modules\Rental\Document\SignatureImage`, #708 IT-16): a PNG, re-encoded
 * from whatever was drawn or imported.
 *
 * **Re-encoded, never kept as received.** Decoding the image and writing a
 * fresh PNG drops every byte that was not a pixel — EXIF, a GPS position, a
 * comment, anything appended after the image data — and it is also the
 * check that the bytes are an image at all: what GD cannot decode is
 * refused here, before anything is encrypted, stored or put in a document.
 *
 * **Pixels only, never a stroke.** What arrives from a drawing pad is the
 * PNG the canvas exported, and that is all this class reads: no point, no
 * timing, no pressure, nothing of how the signature was drawn. Kept that
 * way, a signature is an image and not biometric data (RGPD art. 9).
 *
 * Shrunk to fit `MAX_WIDTH` × `MAX_HEIGHT`: a signature printed at the
 * bottom of a page needs no more, and a twelve-megapixel photograph of a
 * sheet of paper would otherwise be carried into every signed document.
 * Transparency is kept, so a drawn signature sits on the page rather than
 * on a white box.
 */
final class SignatureImage
{
    /** What an import may weigh before it is even decoded. */
    public const MAX_INPUT_BYTES = 5 * 1024 * 1024;

    public const MAX_WIDTH = 1200;
    public const MAX_HEIGHT = 400;

    /**
     * The canvas an image may declare. Five megabytes of compressed PNG can
     * announce a canvas far larger, and decoding allocates width × height
     * × 4 bytes before anything here could refuse it: past `memory_limit`
     * the request dies with a fatal error no exception handler catches.
     * 25 megapixels is a phone photograph several times over — stricter
     * than the site-wide {@see ImageDimensionGuard}, which is checked too.
     */
    public const MAX_PIXELS = 25_000_000;

    private const ACCEPTED = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    private const UNREADABLE_DRAWING = "La signature tracée n'a pas pu être lue. Recommencez le tracé.";
    private const UNREADABLE_IMAGE = "Cette image n'a pas pu être lue. Essayez un autre fichier.";
    private const TOO_HEAVY = 'Cette image est vide ou trop lourde : 5 Mo au plus.';
    private const TOO_LARGE = 'Cette image est trop grande : 25 mégapixels au plus.';

    /**
     * The `data:image/png;base64,…` a drawing pad produces, as a kept PNG.
     *
     * @throws SignatureException when it is not one
     */
    public static function fromDataUrl(#[\SensitiveParameter] string $dataUrl): SignaturePng
    {
        if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $match) !== 1) {
            throw new SignatureException(self::UNREADABLE_DRAWING);
        }

        $bytes = base64_decode($match[1], true);
        if ($bytes === false) {
            throw new SignatureException(self::UNREADABLE_DRAWING);
        }

        return self::fromBytes($bytes);
    }

    /**
     * An imported file, read from where PHP put the upload.
     *
     * The size is checked on disk before a byte is read, so a file far past
     * the ceiling is never loaded into memory. Checking that the path IS an
     * upload (`is_uploaded_file()`) is the caller's job: it is the one that
     * holds the request.
     *
     * @throws SignatureException when it is not an accepted image
     */
    public static function fromUploadedFile(string $path): SignaturePng
    {
        $size = @filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_INPUT_BYTES) {
            throw new SignatureException(self::TOO_HEAVY);
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new SignatureException(self::UNREADABLE_IMAGE);
        }

        return self::fromBytes($bytes);
    }

    /**
     * Any accepted image — PNG, JPEG or WebP — as a fresh, bounded PNG.
     *
     * @throws SignatureException when it is not an accepted image
     */
    public static function fromBytes(#[\SensitiveParameter] string $bytes): SignaturePng
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new SignatureException(self::TOO_HEAVY);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            throw new SignatureException('La signature doit être une image PNG, JPEG ou WebP.');
        }

        try {
            ImageDimensionGuard::assertWithinCeilingFromString($bytes);
        } catch (ImageDimensionException $e) {
            throw new SignatureException(self::TOO_LARGE, 0, $e);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new SignatureException(self::TOO_LARGE);
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new SignatureException(self::UNREADABLE_IMAGE);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            throw new SignatureException(self::UNREADABLE_IMAGE);
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
        if ($transparent !== false) {
            imagefill($target, 0, 0, $transparent);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 9);
        $png = (string) ob_get_clean();

        if ($png === '') {
            throw new SignatureException("Cette image n'a pas pu être enregistrée. Essayez un autre fichier.");
        }

        return new SignaturePng($png);
    }
}
