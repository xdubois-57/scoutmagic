<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * The PDOStatement InstrumentedPdo hands out: every execute() is one
 * statement in QueryCounter, timed. Nothing else changes — same fetch
 * modes, same errors, same results.
 */
final class InstrumentedStatement extends \PDOStatement
{
    protected function __construct()
    {
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        $start = microtime(true);
        try {
            return parent::execute($params);
        } finally {
            QueryCounter::record(microtime(true) - $start);
        }
    }
}
