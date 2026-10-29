<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Core\Database;

/** A shared-PDO unit of work; a caller's existing transaction stays its own. */
final class TransactionScope
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(\PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            self::rollback($pdo);
            throw $error;
        }
    }

    private static function rollback(\PDO $pdo): void
    {
        // A deadlock or a disconnected server may already have rolled back.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
