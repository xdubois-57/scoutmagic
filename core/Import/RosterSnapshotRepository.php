<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Config\ScoutYearService;
use Core\Service\DateInput;

/**
 * Reads and writes the roster snapshots (`schema/core.sql`).
 *
 * **This lives in the core, and the `fees_` table prefix is history, not
 * ownership.** The module `fees` was the first thing that needed to know
 * what Desk contained on a given day, so the tables were born there — the
 * same way `EncryptedFileStorageService` was born of finance receipts and
 * `list_editor` of the banners. But a frozen roster is a fact about
 * members, not about subscriptions, and the core's own import is what
 * produces it. A core that needed an optional module in order to describe
 * its own import is the inversion `ARCHITECTURE.md` §7.4 forbids. The
 * table names kept their prefix on the move: renaming them would strand
 * `fees_invoices`' foreign key on any installation that already has one,
 * and this repo's migration runner deliberately never drops a table or a
 * constraint.
 *
 * The write path runs inside the Desk import's own transaction
 * (`Core\Import\DeskImportService`), which is why {@see capture()} uses a
 * season-label read, two bounded inserts and an update — an `INSERT ... SELECT` over the whole
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

    /** @return array<int, RosterMembershipFacts> active persistent member id => current facts */
    public function findMembershipFactsForYear(int $scoutYearId, \DateTimeImmutable $asOf): array
    {
        $previousLabel = $this->previousYearLabel($scoutYearId);
        $statement = $this->pdo->prepare(
            'SELECT my.member_id, ' . self::membershipProjection() . '
             FROM member_years my
             LEFT JOIN scout_years previous_year ON previous_year.label = ?
             WHERE my.scout_year_id = ? AND my.is_active = 1'
        );
        $statement->execute([$asOf->format('Y-m-d H:i:s'), $previousLabel, $scoutYearId]);
        $facts = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $facts[(int) $row['member_id']] = self::membershipFacts($row);
        }

        return $facts;
    }

    /** The same projection feeds live reads and the bounded snapshot INSERT. */
    private static function membershipProjection(): string
    {
        return '(SELECT MIN(starts.start_date) FROM member_functions starts
                 WHERE starts.member_year_id = my.id) AS first_function_start,
                (SELECT CASE WHEN COUNT(*) > 0 AND COUNT(starts.start_date) = COUNT(*) THEN 1 ELSE 0 END
                 FROM member_functions starts WHERE starts.member_year_id = my.id) AS function_starts_complete,
                CASE WHEN EXISTS (
                    SELECT 1 FROM member_years previous_member
                    WHERE previous_member.member_id = my.member_id
                      AND previous_member.scout_year_id = previous_year.id
                ) THEN 1 WHEN EXISTS (
                    SELECT 1 FROM import_journal previous_import
                    WHERE previous_import.scout_year_id = previous_year.id AND previous_import.imported_at <= ?
                ) THEN 0 ELSE NULL END AS present_previous_year';
    }

    private function previousYearLabel(int $scoutYearId): ?string
    {
        $statement = $this->pdo->prepare('SELECT label FROM scout_years WHERE id = ?');
        $statement->execute([$scoutYearId]);
        $label = $statement->fetchColumn();

        return $label === false ? null : ScoutYearService::previousLabel((string) $label);
    }

    /** @param array<string, mixed> $row */
    private static function membershipFacts(array $row): RosterMembershipFacts
    {
        return new RosterMembershipFacts(
            DateInput::fromStorage(
                $row['first_function_start'] === null ? null : (string) $row['first_function_start']
            ),
            $row['function_starts_complete'] === null ? null : (bool) $row['function_starts_complete'],
            $row['present_previous_year'] === null ? null : (bool) $row['present_previous_year']
        );
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
    public function capture(int $scoutYearId, \DateTimeImmutable $takenAt, ?int $importJournalId = null): RosterSnapshot
    {
        $previousLabel = $this->previousYearLabel($scoutYearId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_roster_snapshots (scout_year_id, import_journal_id, taken_at, member_count) VALUES (?, '
                . '?, ?, 0)'
        );
        $stmt->execute([$scoutYearId, $importJournalId, $takenAt->format('Y-m-d H:i:s')]);
        $snapshotId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_roster_snapshot_members
                 (snapshot_id, member_id, fee_category_id, section_id, function_role, function_id, formation_level, '
                . 'leaving, first_function_start, function_starts_complete, present_previous_year)
             SELECT ?, my.member_id, my.fee_category_id, mf.section_id, f.role, f.id, my.formation_level, my.leaving, '
                . self::membershipProjection() . '
             FROM member_years my
             LEFT JOIN scout_years previous_year ON previous_year.label = ?
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
        $stmt->execute([$snapshotId, $takenAt->format('Y-m-d H:i:s'), $previousLabel, $scoutYearId]);
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
            'SELECT member_id, fee_category_id, section_id, function_role, function_id, formation_level, leaving,
                    first_function_start, function_starts_complete, present_previous_year
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
                $row['function_id'] === null ? null : (int) $row['function_id'],
                (bool) $row['leaving'],
                self::membershipFacts($row)
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * The snapshot one import froze, or null when that import predates the
     * link (or its snapshot has been purged).
     */
    public function findByImport(int $importJournalId): ?RosterSnapshot
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, scout_year_id, taken_at, member_count
             FROM fees_roster_snapshots
             WHERE import_journal_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$importJournalId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Delete every snapshot of a scout year, members included.
     *
     * Called only by `ImportRetentionService`, which deletes whole seasons
     * — the rows go with the import row and the kept CSV, or none of them
     * goes. The member rows are removed explicitly rather than left to
     * `fk_frsm_snapshot`'s cascade, so this behaves identically on SQLite
     * (where foreign keys are off unless asked for) and on MySQL.
     */
    public function deleteForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM fees_roster_snapshot_members
             WHERE snapshot_id IN (SELECT id FROM fees_roster_snapshots WHERE scout_year_id = ?)'
        );
        $stmt->execute([$scoutYearId]);

        $stmt = $this->pdo->prepare('DELETE FROM fees_roster_snapshots WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);

        return $stmt->rowCount();
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
            DateInput::requireFromStorage((string) $row['taken_at'], 'taken_at'),
            (int) $row['member_count']
        );
    }
}
