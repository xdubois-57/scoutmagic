<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * Says, before GD decodes a photo, whether the decode can fit in the memory
 * PHP still allows this process (issue #1449).
 *
 * Core\Image\ImageDimensionGuard caps a photo at 50 megapixels, a fixed
 * number with no relation to the host's `memory_limit`: a 48 MP phone shot
 * passes it and then asks GD for ~200 MB, twice that while an EXIF rotation
 * holds the rotated copy beside the original. Exhausting `memory_limit` is a
 * fatal error, not an exception — no `catch` runs, the cron pass dies and the
 * media stays « en traitement » for good. The only place the failure can be
 * turned into a refusal is before the allocation, which is what this class
 * is for.
 *
 * The estimate is the PEAK of ImageProcessingService::process(), not a sum
 * of everything it ever allocates:
 *
 * - NOT the source file as a string: it is already read when the check
 *   runs, so memory_get_usage() — and with it availableBytes() — has
 *   counted it once already (#1558 review);
 * - the decoded bitmap: GD's truecolor image is one `int` (4 bytes) per
 *   pixel, allocated one row at a time, each row rounded up to Zend's 4 KiB
 *   page, plus a row-pointer array;
 * - then the larger of two moments: an EXIF rotation, which holds a second
 *   bitmap of the same size until the first is destroyed, and the "large"
 *   rendition canvas, which sits beside the source bitmap while it is
 *   resampled (medium and thumb come after it and are smaller);
 * - a fixed allowance (OVERHEAD_BYTES) for what is not modelled: libjpeg and
 *   libpng working buffers, the encoded renditions and output buffer, the
 *   allocations PHP itself makes between the check and the decode.
 *
 * The EXIF read's base64 copies of the file (the `data://` URI
 * exif_read_data() is given, and the base64_encode() result it is built
 * from) are NOT beside the bitmap: process() reads the orientation before
 * decoding, precisely so that those copies are released first. They are
 * bounded by the upload size cap, not by the pixel count this class is
 * about.
 *
 * "Available" is `memory_limit` minus memory_get_usage(true) — the memory
 * Zend has already reserved from the system, which is what the limit is
 * checked against — and a limit of -1 means unlimited: nothing is refused.
 * A PHP built against the system libgd rather than the bundled one
 * allocates outside the Zend heap, so the limit does not even apply to the
 * bitmap; refusing on the estimate is then merely conservative.
 */
final class PhotoMemoryBudget
{
    /** GD truecolor: one 32-bit int per pixel. */
    public const BYTES_PER_PIXEL = 4;

    /** Zend MM hands out "large" blocks in whole pages. */
    private const PAGE_BYTES = 4096;

    /** Size of one row pointer in GD's `tpixels` array. */
    private const ROW_POINTER_BYTES = 8;

    public const OVERHEAD_BYTES = 16 * 1024 * 1024;

    /** @var \Closure(): string */
    private \Closure $memoryLimit;

    /** @var \Closure(): int */
    private \Closure $memoryUsage;

    /**
     * Both readers are injectable so a test can simulate a host's limit;
     * production reads the real ini value and the real usage at check time.
     *
     * @param (\Closure(): string)|null $memoryLimit the raw `memory_limit` ini value
     * @param (\Closure(): int)|null $memoryUsage bytes already reserved by this process
     */
    public function __construct(?\Closure $memoryLimit = null, ?\Closure $memoryUsage = null)
    {
        $this->memoryLimit = $memoryLimit ?? static fn(): string => (string) ini_get('memory_limit');
        $this->memoryUsage = $memoryUsage ?? static fn(): int => memory_get_usage(true);
    }

    /**
     * A budget with a fixed limit and usage, for tests and for a caller that
     * already knows both.
     */
    public static function fixed(string $memoryLimit, int $memoryUsage): self
    {
        return new self(static fn(): string => $memoryLimit, static fn(): int => $memoryUsage);
    }

    /**
     * Parses a `memory_limit` value the way PHP reads it: a plain byte
     * count, or a number followed by K, M or G (either case). Returns null
     * for "no limit": -1 (or any negative), and anything unparseable, since
     * refusing every photo on a value we cannot read would be worse than the
     * fatal this class prevents.
     */
    public static function parseMemoryLimit(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^(-?\d+)\s*([kmg]?)$/i', $value, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];
        if ($number <= 0) {
            return null;
        }

        return match (strtolower($matches[2])) {
            'k' => $number * 1024,
            'm' => $number * 1024 * 1024,
            'g' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }

    /**
     * Bytes still available under `memory_limit`, or null when there is no
     * limit. Never negative.
     */
    public function availableBytes(): ?int
    {
        $limit = self::parseMemoryLimit(($this->memoryLimit)());
        if ($limit === null) {
            return null;
        }

        return max(0, $limit - ($this->memoryUsage)());
    }

    /**
     * Peak bytes ImageProcessingService::process() needs for this photo.
     *
     * @param int $largeLongSide the long side of the "large" rendition, i.e.
     *                           the biggest canvas created beside the source
     */
    public function requiredBytes(int $width, int $height, bool $rotates, int $largeLongSide): int
    {
        $bitmap = self::bitmapBytes($width, $height);

        $longSide = max($width, $height, 1);
        $scale = min(1.0, max(0, $largeLongSide) / $longSide);
        $canvas = self::bitmapBytes(
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale))
        );

        $secondImage = max($rotates ? $bitmap : 0, $canvas);

        return $bitmap + $secondImage + self::OVERHEAD_BYTES;
    }

    /**
     * @return array{required: int, available: int}|null the shortfall, or null
     *         when the photo fits (or there is no limit)
     */
    public function shortfall(int $width, int $height, bool $rotates, int $largeLongSide): ?array
    {
        $available = $this->availableBytes();
        if ($available === null) {
            return null;
        }

        $required = $this->requiredBytes($width, $height, $rotates, $largeLongSide);

        return $required > $available ? ['required' => $required, 'available' => $available] : null;
    }

    private static function bitmapBytes(int $width, int $height): int
    {
        $width = max(0, $width);
        $height = max(0, $height);
        $rowBytes = (int) (ceil($width * self::BYTES_PER_PIXEL / self::PAGE_BYTES) * self::PAGE_BYTES);

        return $height * ($rowBytes + self::ROW_POINTER_BYTES);
    }
}
