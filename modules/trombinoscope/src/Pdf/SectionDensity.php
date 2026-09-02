<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Pdf;

/**
 * How tightly a section's own page packs its portraits, by staff size —
 * the same stepped principle as {@see DirectoryDensity}, applied to the
 * other axis.
 *
 * | Animateurs | Columns | Portrait |
 * |------------|---------|----------|
 * | up to 9    | 3       | large    |
 * | 10 to 16   | 4       | tight    |
 * | beyond     | 5       | compact  |
 *
 * Three columns is the shape a staff of six to nine wants. Past that the
 * answer is a tighter grid rather than a second sheet: a section that
 * spilled would put half its animateurs on a page a parent has no reason
 * to print.
 */
class SectionDensity
{
    private function __construct(
        public readonly int $columns,
        /** Portrait side, in millimetres. */
        public readonly float $portrait,
        public readonly float $totemSize,
        public readonly float $civilNameSize,
        public readonly float $contactSize,
        /** Padding inside a portrait card, in millimetres. */
        public readonly float $padding,
        /** Gutter between two portraits, in millimetres. */
        public readonly float $gap,
        /**
         * How many characters of a totem or a civil name survive. These
         * two MAY be shortened — losing three letters of a compound name
         * stops nobody from acting. An address never is.
         */
        public readonly int $nameLimit
    ) {
    }

    public static function forStaffCount(int $staffCount): self
    {
        if ($staffCount <= 9) {
            return new self(
                columns: 3,
                portrait: 30.0,
                totemSize: 12.0,
                civilNameSize: 9.5,
                contactSize: 8.0,
                padding: 2.4,
                gap: 3.5,
                nameLimit: 26
            );
        }

        if ($staffCount <= 16) {
            return new self(
                columns: 4,
                portrait: 24.0,
                totemSize: 10.5,
                civilNameSize: 8.5,
                contactSize: 7.2,
                padding: 1.8,
                gap: 2.6,
                nameLimit: 20
            );
        }

        return new self(
            columns: 5,
            portrait: 19.0,
            totemSize: 9.0,
            civilNameSize: 7.5,
            contactSize: 6.5,
            padding: 1.4,
            gap: 2.0,
            nameLimit: 16
        );
    }
}
