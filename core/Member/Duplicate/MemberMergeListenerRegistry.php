<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

/**
 * The {@see MemberMergeListener}s of the enabled modules (issue #1462).
 *
 * A mutable registry built empty by the composition root, as
 * {@see \Core\Import\DeskImportListenerRegistry} is: each module block
 * registers its listener from its own enabled block, and the merge reads the
 * registry when it runs.
 */
class MemberMergeListenerRegistry
{
    /** @var MemberMergeListener[] */
    private array $listeners = [];

    public function register(MemberMergeListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** @return MemberMergeListener[] */
    public function all(): array
    {
        return $this->listeners;
    }
}
