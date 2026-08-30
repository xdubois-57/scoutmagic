<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * Who holds a certificate of one category for one scout year, and who does
 * not.
 *
 * Two lists rather than one flagged list, because they answer two different
 * questions and only one of them is urgent: the missing list is what a chef
 * d'unité copies into an e-mail to the federation. After three partial
 * files nobody reconciles three batches by hand.
 */
final class Coverage
{
    /**
     * @param list<MemberSummary> $covered
     * @param list<MemberSummary> $missing
     */
    public function __construct(
        public readonly array $covered,
        public readonly array $missing
    ) {
    }

    public function total(): int
    {
        return count($this->covered) + count($this->missing);
    }

    /**
     * Whole percent of the roster covered, 0 when the year holds nobody —
     * never a division by zero, and never « 0 sur 0 » read as a failure.
     */
    public function percentage(): int
    {
        $total = $this->total();

        return $total === 0 ? 0 : (int) round(count($this->covered) * 100 / $total);
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }
}
