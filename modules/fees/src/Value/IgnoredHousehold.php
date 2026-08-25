<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/** A household a chef d'unité set aside, and the reason they gave. */
final class IgnoredHousehold
{
    public function __construct(
        public readonly int $id,
        public readonly string $addressBlindIndex,
        public readonly string $compositionHash,
        public readonly string $reason,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    /**
     * True when this decision still covers the household as it stands now.
     *
     * A household whose composition changed comes back: the decision was
     * taken about a set of people, and a new arrival or a departure is
     * exactly the event that makes it worth looking at again.
     */
    public function stillApplies(string $currentCompositionHash): bool
    {
        return hash_equals($this->compositionHash, $currentCompositionHash);
    }
}
