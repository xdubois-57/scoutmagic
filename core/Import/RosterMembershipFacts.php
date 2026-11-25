<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/** Operational membership facts, independent of any subscription policy. */
final readonly class RosterMembershipFacts
{
    public function __construct(
        public ?\DateTimeImmutable $firstFunctionStart = null,
        public ?bool $allFunctionStartsKnown = null,
        public ?bool $presentPreviousYear = null
    ) {
    }
}
