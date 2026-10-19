<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * One of the three phases of the workflow, with the steps of this site
 * that fall in it. A phase with no step left is not built at all.
 */
final class TransitionPhase
{
    /**
     * @param list<TransitionStep> $steps
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly ?string $note,
        public readonly bool $current,
        public readonly array $steps
    ) {
    }
}
