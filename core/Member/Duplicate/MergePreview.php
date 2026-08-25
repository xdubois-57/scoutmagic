<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

/**
 * What a merge would move, counted before it happens.
 *
 * **A merge cannot be undone.** There is deliberately no undo mechanism —
 * one would have to remember which of a hundred repointed rows came from
 * where, and would be wrong the first time somebody edited one in
 * between. What replaces it is this: the screen states exactly what will
 * be reattached, in units a chef d'unité can check against the two member
 * pages side by side, before the confirmation.
 */
final class MergePreview
{
    public function __construct(
        public readonly int $scoutYears = 0,
        public readonly int $photos = 0,
        public readonly int $badges = 0,
        public readonly int $documents = 0,
        public readonly int $sectionPeriods = 0,
        public readonly int $files = 0,
        public readonly int $emailAddresses = 0,
        public readonly int $notifications = 0,
        public readonly int $rosterSnapshotRows = 0
    ) {
    }

    public function total(): int
    {
        return $this->scoutYears + $this->photos + $this->badges + $this->documents
            + $this->sectionPeriods + $this->files + $this->emailAddresses
            + $this->notifications + $this->rosterSnapshotRows;
    }
}
