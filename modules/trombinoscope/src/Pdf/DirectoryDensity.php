<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Pdf;

/**
 * How tightly the directory page packs itself, as a function of how many
 * sections the unit has.
 *
 * Steps rather than continuous scaling. A unit has between four and twenty
 * sections in practice, so three steps cover the whole range; and a step is
 * something a test can assert on, whereas "shrink by 7%" is a number
 * nobody can check. It is also the more robust choice under dompdf, which
 * has neither flexbox nor grid: the composition is a table whose column
 * count has to be decided before anything is laid out, not adjusted once
 * the result is measured.
 *
 * | Sections | Columns | Portrait |
 * |----------|---------|----------|
 * | up to 6  | 2       | large    |
 * | 7 to 10  | 2       | tight    |
 * | beyond   | 3       | compact  |
 *
 * The footer's address list follows the same principle on its own
 * threshold: two columns up to eight sections, three beyond, at a smaller
 * size — an address is never shortened, so the only thing left to give is
 * the type size.
 */
class DirectoryDensity
{
    private function __construct(
        public readonly int $columns,
        /** Portrait side, in millimetres. */
        public readonly float $portrait,
        /** Section colour band width, in millimetres. */
        public readonly float $band,
        public readonly float $sectionNameSize,
        public readonly float $totemSize,
        public readonly float $civilNameSize,
        public readonly float $contactSize,
        /** Padding inside a card, in millimetres. */
        public readonly float $padding,
        /** Gutter between two cards, in millimetres. */
        public readonly float $gap
    ) {
    }

    public static function forSectionCount(int $sectionCount): self
    {
        if ($sectionCount <= 6) {
            return new self(
                columns: 2,
                portrait: 19.0,
                band: 3.4,
                sectionNameSize: 12.0,
                totemSize: 13.0,
                civilNameSize: 10.5,
                contactSize: 9.5,
                padding: 2.6,
                gap: 4.0
            );
        }

        if ($sectionCount <= 10) {
            return new self(
                columns: 2,
                portrait: 15.0,
                band: 2.7,
                sectionNameSize: 10.5,
                totemSize: 11.5,
                civilNameSize: 9.5,
                contactSize: 8.5,
                padding: 2.0,
                gap: 2.8
            );
        }

        return new self(
            columns: 3,
            portrait: 12.0,
            band: 2.0,
            sectionNameSize: 9.0,
            totemSize: 10.0,
            civilNameSize: 8.5,
            contactSize: 7.5,
            padding: 1.6,
            gap: 2.0
        );
    }

    /**
     * Columns of the footer's "write to a section" address list. Its own
     * threshold, deliberately: the footer holds one short line per section
     * while a card holds a portrait and up to five lines, so the point at
     * which each of them needs a third column is not the same.
     */
    public function footerColumns(int $sectionCount): int
    {
        return $sectionCount > 8 ? 3 : 2;
    }

    /**
     * Type size of a footer address line, in points. Smaller past ten
     * sections — never shorter: a clipped address is an address nobody can
     * write to, which is the one thing this document exists to prevent.
     */
    public function footerSize(int $sectionCount): float
    {
        return $sectionCount > 10 ? 7.0 : 8.5;
    }
}
