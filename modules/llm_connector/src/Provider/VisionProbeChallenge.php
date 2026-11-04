<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/** A tiny synthetic PNG: its random answer appears only in image pixels. */
final class VisionProbeChallenge
{
    private const DIGITS = [
        '01110100011001110101110011000101110',
        '00100011000010000100001000010001110',
        '01110100010000100010001000100011111',
        '11110000010000101110000010000111110',
        '00010001100101010010111110001000010',
        '11111100001000011110000010000111110',
        '01110100001000011110100011000101110',
        '11111000010001000100010000100001000',
        '01110100011000101110100011000101110',
        '01110100011000101111000010000101110',
    ];

    private function __construct(public readonly string $answer, public readonly string $imageBase64)
    {
    }

    public static function create(): self
    {
        return self::forDigits(sprintf('%08d', random_int(0, 99999999)));
    }

    public static function forDigits(string $digits): self
    {
        if (preg_match('/\A[0-9]{8}\z/', $digits) !== 1) {
            throw new \InvalidArgumentException('A vision probe needs exactly eight decimal digits.');
        }
        // Grayscale PNG, no GD/Imagick dependency and no embedded text/metadata.
        $scanlines = '';
        for ($y = 0; $y < 112; $y++) {
            $scanlines .= "\0";
            for ($x = 0; $x < 352; $x++) {
                $scanlines .= self::pixel($digits, $x - 32, $y - 32);
            }
        }
        $compressed = gzcompress($scanlines);
        if ($compressed === false) {
            throw new \RuntimeException('Could not compress the synthetic probe image.');
        }
        $png = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', 352, 112, 8, 0, 0, 0, 0))
            . self::chunk('IDAT', $compressed) . self::chunk('IEND', '');
        return new self($digits, base64_encode($png));
    }

    private static function pixel(string $digits, int $x, int $y): string
    {
        if ($y < 0 || $y >= 42 || $x < 0 || $x >= 288 || $x % 36 >= 30) {
            return "\xff";
        }
        $glyph = self::DIGITS[(int) $digits[intdiv($x, 36)]];
        $position = intdiv($y, 6) * 5 + intdiv($x % 36, 6);
        return $glyph[$position] === '1' ? "\0" : "\xff";
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
