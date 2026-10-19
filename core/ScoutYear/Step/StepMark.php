<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * Who marked a human step done, and when (scout_year_transition_steps).
 * $doneBy is a display name, null when the account is gone or nameless.
 */
final class StepMark
{
    public function __construct(
        public readonly \DateTimeImmutable $doneAt,
        public readonly ?string $doneBy
    ) {
    }
}
