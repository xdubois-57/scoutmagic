<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

/**
 * The two statements a {@see MemberMergeListener} needs for most of its
 * tables (issue #1462), so a module names its tables and its rule instead of
 * writing the same SQL again. The table and column names are the module's
 * own constants, never input; they are still checked to be plain
 * identifiers before they reach a statement.
 */
class MergeRowRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** How many rows of $table carry $memberId in $column. */
    public function count(string $table, string $column, int $memberId): int
    {
        self::assertIdentifiers($table, $column);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A plain repoint: every row of $table carrying $duplicateMemberId in
     * $column now carries $keptMemberId. For a column no unique index
     * involves. Returns how many rows moved.
     */
    public function repoint(string $table, string $column, int $keptMemberId, int $duplicateMemberId): int
    {
        self::assertIdentifiers($table, $column);
        $stmt = $this->pdo->prepare("UPDATE {$table} SET {$column} = ? WHERE {$column} = ?");
        $stmt->execute([$keptMemberId, $duplicateMemberId]);

        return $stmt->rowCount();
    }

    /**
     * A repoint under a unique index made of $column and $scope (the other
     * columns of that index). Where both identities hold a row for the same
     * $scope, one of them goes, by $winner:
     * - `'kept'`: the kept identity's row stays;
     * - a column name: the row with the greater value of it stays (the kept
     *   identity's on a tie, or when the column is null on the duplicate's).
     * Then the duplicate's remaining rows move. Returns how many of the
     * duplicate's rows were handled (moved, or settled against the kept
     * identity's) and how many were collisions.
     *
     * @param list<string> $scope
     * @return array{handled: int, collisions: int}
     */
    public function repointUnique(
        string $table,
        string $column,
        array $scope,
        string $winner,
        int $keptMemberId,
        int $duplicateMemberId
    ): array {
        self::assertIdentifiers($table, $column, ...$scope);
        if ($winner !== 'kept') {
            self::assertIdentifiers($winner);
        }

        $join = $scope === []
            ? '1 = 1'
            : implode(' AND ', array_map(static fn(string $c): string => "k.{$c} = d.{$c}", $scope));
        $duplicateWins = $winner === 'kept'
            ? '0'
            : "CASE WHEN d.{$winner} IS NOT NULL AND (k.{$winner} IS NULL OR d.{$winner} > k.{$winner})"
                . ' THEN 1 ELSE 0 END';
        $stmt = $this->pdo->prepare(
            "SELECT d.id AS duplicate_row, k.id AS kept_row, {$duplicateWins} AS duplicate_wins
             FROM {$table} d
             JOIN {$table} k ON k.{$column} = ? AND {$join}
             WHERE d.{$column} = ?"
        );
        $stmt->execute([$keptMemberId, $duplicateMemberId]);
        $collisions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $settledAgainstKept = 0;
        foreach ($collisions as $row) {
            if ((int) $row['duplicate_wins'] === 1) {
                $delete->execute([(int) $row['kept_row']]);
            } else {
                $delete->execute([(int) $row['duplicate_row']]);
                $settledAgainstKept++;
            }
        }

        return [
            'handled' => $this->repoint($table, $column, $keptMemberId, $duplicateMemberId) + $settledAgainstKept,
            'collisions' => count($collisions),
        ];
    }

    private static function assertIdentifiers(string ...$names): void
    {
        foreach ($names as $name) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw new \InvalidArgumentException('Not an identifier: ' . $name);
            }
        }
    }
}
