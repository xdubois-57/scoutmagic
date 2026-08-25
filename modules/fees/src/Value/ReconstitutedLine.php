<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/**
 * One invoice line against what the roster snapshot says it should have
 * been: "did the federation count the right number of people".
 *
 * `$expectedQuantity` is **null when the site cannot say**, never zero —
 * and the two are not the same claim. An unknown tariff reference, a line
 * with no section, a global adjustment: each of those leaves the count
 * undetermined, `$undeterminedReason` says which, and the screen shows the
 * line without a verdict rather than accusing the federation of billing
 * for nobody.
 *
 * The expected count comes from the SNAPSHOT — what Desk held — never from
 * a tariff calculation. That distinction is the difference between this
 * report and the fee-accuracy screen: one checks the count, the other
 * checks the categories.
 */
final class ReconstitutedLine
{
    public const UNDETERMINED_UNKNOWN_REFERENCE = 'unknown_reference';
    public const UNDETERMINED_NO_SECTION = 'no_section';
    public const UNDETERMINED_GLOBAL_ADJUSTMENT = 'global_adjustment';
    public const UNDETERMINED_NO_SNAPSHOT = 'no_snapshot';

    public function __construct(
        public readonly string $reference,
        public readonly string $descriptor,
        public readonly ?int $sectionId,
        public readonly ?string $sectionLabel,
        public readonly int $unitPriceCents,
        public readonly int $billedQuantity,
        public readonly int $amountCents,
        public readonly ?int $expectedQuantity,
        public readonly ?string $undeterminedReason
    ) {
    }

    public function isDetermined(): bool
    {
        return $this->expectedQuantity !== null;
    }

    /** Positive when the federation billed more people than the roster held. */
    public function difference(): ?int
    {
        return $this->expectedQuantity === null ? null : $this->billedQuantity - $this->expectedQuantity;
    }

    public function matches(): bool
    {
        return $this->difference() === 0;
    }

    /** What the difference costs, at the line's own unit price. Null when undetermined. */
    public function differenceCents(): ?int
    {
        $difference = $this->difference();

        return $difference === null ? null : $difference * $this->unitPriceCents;
    }
}
