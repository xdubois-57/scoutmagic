<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * A \PDO that counts and times what it runs, into QueryCounter.
 *
 * query() and exec() are one statement each; a prepared statement counts
 * once per execute() (InstrumentedStatement), never at prepare() — a
 * loop that prepares once and executes N times has issued N statements,
 * and that is the number worth seeing. Built by Core\Database\Connection
 * for the real database; tests that need the tally build it over SQLite.
 */
class InstrumentedPdo extends \PDO
{
    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [InstrumentedStatement::class, []]);
    }

    public function exec(string $statement): int|false
    {
        $start = microtime(true);
        try {
            return parent::exec($statement);
        } finally {
            QueryCounter::record(microtime(true) - $start);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $start = microtime(true);
        try {
            return $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally {
            QueryCounter::record(microtime(true) - $start);
        }
    }
}
