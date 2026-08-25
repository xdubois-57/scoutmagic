<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Attention;

/**
 * One thing that is currently not right about the unit.
 *
 * **Nothing here is stored, and nothing is ever acknowledged.** An
 * attention point disappears from the page because it stopped being true
 * — somebody removed the member, reassigned the badge, updated Desk — not
 * because anyone clicked. That is the whole design: it makes the
 * mechanism tiny, and it makes it honest. A stored point needs an
 * acknowledgement flow, and an acknowledgement flow accumulates "already
 * dealt with" entries that stopped being dealt with months ago.
 *
 * It is the exact opposite of {@see \Core\Import\ImportDiff}, which is a
 * dated, frozen fact. An import *reveals* attention points; it never
 * creates them, and they outlive it.
 *
 * Four things every point carries, and they are the four a reader needs:
 * what is wrong, why it matters, what to do about it, and — when there is
 * one — by when.
 */
final class AttentionPoint
{
    /** Something with a deadline, or an access nobody holds. */
    public const SEVERITY_URGENT = 'urgent';

    /** Worth fixing, nothing breaks today. */
    public const SEVERITY_ATTENTION = 'attention';

    public function __construct(
        /** What is wrong, as a sentence. */
        public readonly string $title,
        /** Why it matters — the consequence, not a restatement. */
        public readonly string $why,
        /** What to do, named as an action ("Attribuer le badge"). */
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
        /** An optional deadline, rendered as "dans N jours". */
        public readonly ?\DateTimeImmutable $dueDate = null,
        public readonly string $severity = self::SEVERITY_ATTENTION,
        /**
         * Which contributor produced this, filled in by
         * {@see AttentionService} so a provider cannot mislabel itself.
         */
        public readonly string $source = ''
    ) {
    }

    /** The same point, stamped with the source that produced it. */
    public function withSource(string $source): self
    {
        return new self(
            $this->title,
            $this->why,
            $this->actionLabel,
            $this->actionUrl,
            $this->dueDate,
            $this->severity,
            $source
        );
    }

    /**
     * Whole days from $today to the deadline; negative once it has
     * passed, null when there is no deadline.
     */
    public function daysUntilDue(\DateTimeImmutable $today): ?int
    {
        if ($this->dueDate === null) {
            return null;
        }

        $diff = $today->setTime(0, 0)->diff($this->dueDate->setTime(0, 0));

        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }
}
