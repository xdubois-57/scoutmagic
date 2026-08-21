<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * One reading of one meter, at one end of the stay (§6.22).
 *
 * **The value is in thousandths of a unit, as an integer.** A meter index
 * is not money, but it has money's problem: 1234.567 kWh in a float,
 * differenced against another float and multiplied by a unit price, is how
 * a bill ends up a cent off in a way nobody can reproduce.
 */
final class MeterReading
{
    /** How many stored units make one real unit. */
    public const SCALE = 1000;

    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly int $meterId,
        public readonly ReadingPhase $phase,
        public readonly int $valueMilli,
        public readonly \DateTimeImmutable $readAt,
        public readonly ?int $fileId,
        public readonly ?string $comment,
        public readonly ?int $recordedByMemberId
    ) {
    }

    /**
     * Parses what an operator types — "1234,567", "1234.5", "1234" — into
     * thousandths.
     *
     * Accepts the French decimal comma, like every other numeric field in
     * this module: `(float) "1234,567"` is `1234.0`, a reading silently
     * entered short by more than half a unit with nothing to notice it by.
     *
     * Returns null for anything that is not a number, so a caller can tell
     * "empty field" from "zero".
     */
    public static function parse(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', "\u{202f}", "\u{a0}"], '', trim($value));
        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        // Rounded rather than truncated: a meter showing 1234.5675 is
        // nearer 1234.568 than 1234.567, and truncation would bias every
        // reading downwards.
        return (int) round(((float) $value) * self::SCALE);
    }

    /** `1234,567` — for a field an operator edits. */
    public static function format(int $valueMilli, int $decimals = 3): string
    {
        return number_format($valueMilli / self::SCALE, $decimals, ',', ' ');
    }

    public function formatted(int $decimals = 3): string
    {
        return self::format($this->valueMilli, $decimals);
    }
}
