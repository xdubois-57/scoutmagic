<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Modules\Rental\Service\RentalException;

/**
 * A manager's signature as it is kept (#708, IT-16): a PNG, re-encoded from
 * whatever was drawn or imported.
 *
 * **Re-encoded, never stored as received.** Decoding the image and writing a
 * fresh PNG drops every byte that was not a pixel — EXIF, a GPS position, a
 * comment, anything appended after the image data — and it is also the
 * check that the bytes are an image at all: what GD cannot decode is
 * refused here, before it is encrypted and kept.
 *
 * Shrunk to fit `MAX_WIDTH` × `MAX_HEIGHT`: a signature printed at the
 * bottom of a page needs no more, and a twelve-megapixel photo of a sheet
 * of paper would otherwise be carried into every countersigned contract.
 * Transparency is kept, so a drawn signature sits on the page rather than
 * on a white box.
 */
final class SignatureImage
{
    /** What an import may weigh before it is even decoded. */
    public const MAX_INPUT_BYTES = 5 * 1024 * 1024;

    private const MAX_WIDTH = 1200;
    private const MAX_HEIGHT = 400;

    private const ACCEPTED = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    /**
     * The canvas an image may declare. Five megabytes of compressed PNG can
     * announce a canvas far larger, and decoding allocates width × height
     * × 4 bytes before anything here could refuse it: past `memory_limit`
     * the request dies with a fatal error no `RentalException` catches.
     * 25 megapixels is a phone photograph several times over.
     */
    private const MAX_PIXELS = 25_000_000;

    /**
     * The `data:image/png;base64,…` a drawing pad produces, as PNG bytes.
     *
     * @throws RentalException when it is not one
     */
    public static function fromDataUrl(string $dataUrl): string
    {
        if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $match) !== 1) {
            throw new RentalException("La signature tracée n'a pas pu être lue. Recommencez le tracé.");
        }

        $bytes = base64_decode($match[1], true);
        if ($bytes === false) {
            throw new RentalException("La signature tracée n'a pas pu être lue. Recommencez le tracé.");
        }

        return self::normalize($bytes);
    }

    /**
     * Any accepted image — PNG, JPEG or WebP — as a fresh, bounded PNG.
     *
     * @throws RentalException when it is not an accepted image
     */
    public static function normalize(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new RentalException('Cette image est vide ou trop lourde : 5 Mo au plus.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            throw new RentalException('La signature doit être une image PNG, JPEG ou WebP.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RentalException('Cette image est trop grande : 25 mégapixels au plus.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RentalException("Cette image n'a pas pu être lue. Essayez un autre fichier.");
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            throw new RentalException("Cette image n'a pas pu être lue. Essayez un autre fichier.");
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
            throw new RentalException("Cette image n'a pas pu être enregistrée. Essayez un autre fichier.");
        }

        return $png;
    }
}
