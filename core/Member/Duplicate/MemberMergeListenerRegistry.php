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

    /** @var list<\Closure(): void> */
    private array $afterEachMerge = [];

    public function register(MemberMergeListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** @return MemberMergeListener[] */
    public function all(): array
    {
        return $this->listeners;
    }

    /**
     * $callback runs at the end of every merge, inside its transaction —
     * how {@see MemberMergeListenerRegistrar} records that the modules
     * enabled in this request followed it, so it is not replayed.
     *
     * @param \Closure(): void $callback
     */
    public function afterEachMerge(\Closure $callback): void
    {
        $this->afterEachMerge[] = $callback;
    }

    /** Called by the merge, once its own writes are done and before it commits. */
    public function merged(): void
    {
        foreach ($this->afterEachMerge as $callback) {
            $callback();
        }
    }
}
