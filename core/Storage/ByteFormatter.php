<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage;

/**
 * Byte counts written and read the way this site's French interface spells
 * them: `Mo` means MiB, the step is 1024, and one decimal appears only
 * where it carries information.
 *
 * Lifted out of `Modules\Gallery\Service\DiskSpace::format()`, which is now
 * a two-line delegation to it — the gallery configuration page and the
 * maintenance page must not drift into two spellings of « 1,5 Go ».
 * Formatting was the only part of that class core needed; the rest of it
 * is about one storage location's own warning threshold and stays where it
 * is.
 */
final class ByteFormatter
{
    /** 1024, matching `Mo` = MiB — the convention every size on this site already uses. */
    private const STEP = 1024;

    /** @var array<int, string> */
    private const UNITS = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];

    /**
     * A byte count in French: one decimal from « Go » up to 100, none
     * anywhere else. « 1,5 Go » is worth the digit; « 340,0 Mo » and
     * « 512,0 Go » only look more precise than the measurement is.
     */
    public static function format(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= self::STEP && $unit < count(self::UNITS) - 1) {
            $value /= self::STEP;
            $unit++;
        }

        $decimals = $unit >= 3 && $value < 100 ? 1 : 0;

        return number_format($value, $decimals, ',', ' ') . ' ' . self::UNITS[$unit];
    }

    /**
     * Reads back what a human typed into a size field — `10 Go`, `500 Mo`,
     * `1,5 Go`, or a bare byte count. Returns null for anything empty or
     * unreadable, never a guess and never an exception: the one caller
     * (`DiskBudget`'s declared quota) treats "not stated" and "not
     * readable" identically, as "the admin has not told us their quota".
     *
     * The canonical unit is the byte — that is what the setting is named
     * after and what a raw number means. The suffixes exist because
     * nobody knows their hosting quota in bytes, and typing 10737418240
     * to mean « 10 Go » is a transcription error waiting to happen.
     */
    public static function parse(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        // A non-breaking space is what a copy-paste out of this site's own
        // formatted output carries (number_format above emits a plain one,
        // but the browser and the OS both like to substitute).
        $trimmed = str_replace(["\u{00A0}", "\u{202F}"], ' ', $trimmed);
        $trimmed = str_replace(' ', '', $trimmed);

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)(o|ko|mo|go|to|po)?$/i', $trimmed, $matches) !== 1) {
            return null;
        }

        $number = (float) str_replace(',', '.', $matches[1]);
        $unitIndex = array_search(strtolower($matches[2] ?? 'o'), array_map('strtolower', self::UNITS), true);
        if ($unitIndex === false) {
            return null;
        }

        $bytes = $number * (self::STEP ** $unitIndex);

        // Beyond this a float has lost integer precision anyway, and no
        // hosting quota a scout unit buys is anywhere near it.
        if ($bytes < 0 || $bytes > (float) PHP_INT_MAX) {
            return null;
        }

        return (int) round($bytes);
    }
}
