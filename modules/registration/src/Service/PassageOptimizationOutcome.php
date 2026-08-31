<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * What one run of the optimiser decided — and what it could not do
 * (roadmap IT-18, spec §14).
 *
 * A plan, not a write: the service computes this, the caller applies it in
 * a single transaction. Splitting them is what makes the algorithm
 * testable without a database round trip per candidate solution, and what
 * makes "a partially applied result is worse than a failure" enforceable
 * in one place.
 *
 * **There is no failure state.** The button never refuses to answer: when
 * no distribution satisfies the limits, the least-bad one comes back with
 * `warnings` saying so in French, and a chief decides what to do about it.
 */
final class PassageOptimizationOutcome
{
    /**
     * @param array<int, int> $memberDestinations member id => section id
     * @param array<int, int> $requestSections registration request id => section id
     * @param array<int, string> $warnings French sentences, one per branch
     *        that could not be balanced within the configured limit
     */
    public function __construct(
        public readonly array $memberDestinations,
        public readonly array $requestSections,
        /** Lines already carrying a section, which the run left untouched. */
        public readonly int $keptCount,
        /** Lines this run placed. */
        public readonly int $placedCount,
        public readonly array $warnings = []
    ) {
    }

    public function total(): int
    {
        return count($this->memberDestinations) + count($this->requestSections);
    }
}
