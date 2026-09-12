<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

/**
 * How much room a remote destination says it has.
 *
 * Bytes, like everything else that measures storage in this codebase
 * (`Core\Storage\DiskBudget`), so the screen can format the two the same
 * way and nobody has to remember which number is in what unit.
 */
final class RemoteQuota
{
    public function __construct(
        public readonly int $usedBytes,
        public readonly int $limitBytes
    ) {
    }

    public function freeBytes(): int
    {
        return max(0, $this->limitBytes - $this->usedBytes);
    }
}
