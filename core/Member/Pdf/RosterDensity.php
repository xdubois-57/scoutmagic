<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Pdf;

/**
 * How tightly one section's sheet packs itself, as a function of the
 * longest group it has to draw.
 *
 * Steps rather than continuous scaling, for the reason Modules\
 * Trombinoscope\Pdf\DirectoryDensity gives about its own: a step is
 * something a test can assert on, "shrink by 7%" is a number nobody can
 * check. Sizes live here rather than scattered through style strings in
 * the builder for the same reason.
 *
 * | Longest group | Rows      | Line height |
 * |---------------|-----------|-------------|
 * | up to 12      | roomy     | comfortable |
 * | 13 to 22      | tight     | still one page for a full section |
 * | beyond        | compact   | overflow is expected, and handled |
 *
 * The last step does NOT try to fit everything on one sheet. A section of
 * thirty animés continues on a second page — the builder repeats the
 * section header there through a `<thead>`, which is dompdf's only
 * mechanism for it — and shrinking type until it fitted would produce a
 * sheet nobody can read at arm's length while calling names.
 */
final class RosterDensity
{
    private function __construct(
        /** Type size of a member's line, in points. */
        public readonly float $nameSize,
        /** Vertical padding inside a member's row, in millimetres. */
        public readonly float $rowPadding,
        /** Type size of a group banner ("ANIMÉS · 8"), in points. */
        public readonly float $bannerSize,
        /** Side of the tick box, in millimetres. */
        public readonly float $checkbox,
        /** Type size of a movement badge, in points. */
        public readonly float $badgeSize,
        /** Vertical gap between the two groups, in millimetres. */
        public readonly float $groupGap
    ) {
    }

    public static function forLargestGroup(int $size): self
    {
        if ($size <= 12) {
            return new self(
                nameSize: 11.0,
                rowPadding: 1.5,
                bannerSize: 9.0,
                checkbox: 3.8,
                badgeSize: 7.5,
                groupGap: 4.0
            );
        }

        if ($size <= 22) {
            return new self(
                nameSize: 10.0,
                rowPadding: 1.0,
                bannerSize: 8.5,
                checkbox: 3.4,
                badgeSize: 7.0,
                groupGap: 3.0
            );
        }

        return new self(
            nameSize: 9.0,
            rowPadding: 0.7,
            bannerSize: 8.0,
            checkbox: 3.0,
            badgeSize: 6.5,
            groupGap: 2.5
        );
    }
}
