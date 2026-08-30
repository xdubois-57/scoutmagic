<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

/**
 * What one scheduler slice did — the three facts
 * SchedulerContinuation::shouldHop() needs, and nothing else.
 */
final class SliceOutcome
{
    /**
     * @param bool $heldLock Whether this slice held the exclusion lock. A
     *   slice that did not is not the one doing the work; the one that is
     *   will continue the chain itself.
     * @param int $processed Tasks this slice actually ran to completion or
     *   failure. Zero means the slice achieved nothing, which is never a
     *   reason to hop.
     * @param bool $workRemains Whether tasks are still due. True when the
     *   lock was refused: another process is working, so from this
     *   slice's point of view the queue is not known to be empty.
     */
    public function __construct(
        public readonly bool $heldLock,
        public readonly int $processed,
        public readonly bool $workRemains
    ) {
    }
}
