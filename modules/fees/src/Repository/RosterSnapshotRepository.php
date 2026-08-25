<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Repository;

use Modules\Fees\Value\RosterSnapshot;
use Modules\Fees\Value\RosterSnapshotMember;

/**
 * Reads and writes the roster snapshots (`modules/fees/schema.sql`).
 *
 * The write path runs inside the Desk import's own transaction
 * (`Core\Import\DeskImportListener`), which is why {@see capture()} is two
 * bounded statements and an update — an `INSERT ... SELECT` over the whole
 * roster rather than a loop issuing one INSERT per member. A per-member
 * loop on a 300-member unit is 300 round trips inside a transaction that
 * already holds the entire roster's locks, and any of them failing rolls
 * the import back.
 */
class RosterSnapshotRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Freeze the composition of a scout year's roster.
     *
     * `is_active = 1` is the only filter, and it is what "present in this
     * import" means: `DeskImportService` deactivates every member_year of
     * the year before reactivating the ones the CSV names.
     * `member_years.leaving` is copied, never applied — see the schema.
     *
     * The section and role come from the member's **main** function, and
     * from their first function when Desk flagged none. One row per member
     * rather than one per function, on purpose: an invoice bills a person
     * once, under one section, so a snapshot with two rows for somebody
     * holding two functions would report an expected quantity nobody could
     * reconcile.
     */
    public function capture(int $scoutYearId, \DateTimeImmutable $takenAt): RosterSnapshot
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_roster_snapshots (scout_year_id, taken_at, member_count) VALUES (?, ?, 0)'
        );
        $stmt->execute([$scoutYearId, $takenAt->format('Y-m-d H:i:s')]);
        $snapshotId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_roster_snapshot_members
                 (snapshot_id, member_id, fee_category_id, section_id, function_role, formation_level, leaving)
             SELECT ?, my.member_id, my.fee_category_id, mf.section_id, f.role, my.formation_level, my.leaving
             FROM member_years my
             LEFT JOIN member_functions mf ON mf.id = (
                 SELECT mf2.id FROM member_functions mf2
                 WHERE mf2.member_year_id = my.id
                 ORDER BY mf2.is_main_function DESC, mf2.id ASC
                 LIMIT 1
             )
             LEFT JOIN functions f ON f.id = mf.function_id
             WHERE my.scout_year_id = ?
               AND my.is_active = 1'
        );
        $stmt->execute([$snapshotId, $scoutYearId]);
        $memberCount = $stmt->rowCount();

        $stmt = $this->pdo->prepare('UPDATE fees_roster_snapshots SET member_count = ? WHERE id = ?');
        $stmt->execute([$memberCount, $snapshotId]);

        return new RosterSnapshot($snapshotId, $scoutYearId, $takenAt, $memberCount);
    }

    /** The most recent snapshot of a scout year, or null when none was ever taken. */
    public function findLatestForYear(int $scoutYearId): ?RosterSnapshot
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, scout_year_id, taken_at, member_count
             FROM fees_roster_snapshots
             WHERE scout_year_id = ?
             ORDER BY taken_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$scoutYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every snapshot of a scout year, newest first.
     *
     * @return RosterSnapshot[]
     */
    public function findAllForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, scout_year_id, taken_at, member_count
             FROM fees_roster_snapshots
             WHERE scout_year_id = ?
             ORDER BY taken_at DESC, id DESC'
        );
        $stmt->execute([$scoutYearId]);

        return array_map(fn(array $row): RosterSnapshot => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The rows of one snapshot.
     *
     * @return RosterSnapshotMember[]
     */
    public function findMembers(int $snapshotId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id, fee_category_id, section_id, function_role, formation_level, leaving
             FROM fees_roster_snapshot_members
             WHERE snapshot_id = ?
             ORDER BY member_id'
        );
        $stmt->execute([$snapshotId]);

        return array_map(
            static fn(array $row): RosterSnapshotMember => new RosterSnapshotMember(
                (int) $row['member_id'],
                $row['fee_category_id'] === null ? null : (int) $row['fee_category_id'],
                $row['section_id'] === null ? null : (int) $row['section_id'],
                $row['function_role'] === null ? null : (string) $row['function_role'],
                $row['formation_level'] === null ? null : (string) $row['formation_level'],
                (bool) $row['leaving']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function countForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fees_roster_snapshots WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RosterSnapshot
    {
        return new RosterSnapshot(
            (int) $row['id'],
            (int) $row['scout_year_id'],
            new \DateTimeImmutable((string) $row['taken_at']),
            (int) $row['member_count']
        );
    }
}
