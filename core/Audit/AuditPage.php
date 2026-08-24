<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Audit;

/**
 * One page of an entity's history, plus what the caller needs to decide
 * whether to offer "Afficher plus".
 *
 * `total` is the whole history's size, not this page's: the folded
 * timeline announces "12 modifications, la dernière le …" without
 * loading them all.
 */
class AuditPage
{
    /**
     * @param AuditEntry[] $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total
    ) {
    }

    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->total;
    }
}
