<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * When this module last asked core to notify an item's author about a
 * reaction — the debounce state behind Service\ReactionNoticeThrottle.
 *
 * Two tables, one class, private constructor and named constructors
 * passing string literals: the exact shape (and the exact safety
 * argument) of Repository\ReactionRepository and Repository\
 * ReportRepository. Nothing interpolated into a statement can come from a
 * request; everything that varies per call is a bound parameter
 * (SECURITY.md §1).
 *
 * This records TIMING, never notifications. Core's `notifications` table
 * is the only record of what was sent, and a module must not read it
 * (ARCHITECTURE.md §8.24) — hence this small parallel record of when this
 * module last asked.
 */
class ReactionNoticeRepository
{
    private function __construct(
        private \PDO $pdo,
        private string $table,
        private string $itemColumn
    ) {
    }

    public static function forPosts(\PDO $pdo): self
    {
        return new self($pdo, 'discussion_group_post_reaction_notices', 'post_id');
    }

    public static function forReplies(\PDO $pdo): self
    {
        return new self($pdo, 'discussion_group_reply_reaction_notices', 'reply_id');
    }

    /**
     * The instant this module last asked core to notify about $itemId, or
     * null when it never has.
     */
    public function lastNotifiedAt(int $itemId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT notified_at FROM {$this->table} WHERE {$this->itemColumn} = ?"
        );
        $stmt->execute([$itemId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    /**
     * Stamps "asked just now" for $itemId, creating the row or moving it
     * forward.
     *
     * Written as insert-then-update-on-conflict rather than
     * SELECT-then-branch: two reactions landing in the same instant would
     * otherwise both see "no row" and both insert, and the UNIQUE index
     * would turn the loser into a 500 on someone's perfectly ordinary
     * click. Same SQLSTATE 23000 handling, and the same reasoning, as
     * Repository\ReportRepository::record().
     */
    public function stamp(int $itemId, string $notifiedAt): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table} ({$this->itemColumn}, notified_at) VALUES (?, ?)"
            );
            $stmt->execute([$itemId, $notifiedAt]);

            return;
        } catch (\PDOException $e) {
            // '23000' is the integrity-constraint SQLSTATE, identical on
            // the MySQL and SQLite drivers. Anything else is a real
            // failure and must not be swallowed.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET notified_at = ? WHERE {$this->itemColumn} = ?"
        );
        $stmt->execute([$notifiedAt, $itemId]);
    }
}
