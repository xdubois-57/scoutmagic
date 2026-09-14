<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

/**
 * The `storage_protections` table.
 *
 * Plain rows and no secret: a protection names two locations this site
 * already knows, and every credential it needs to act belongs to one of
 * them. {@see \Core\Storage\Location\StorageLocationRepository::getSecret()}
 * stays the only reader of those.
 */
class StorageProtectionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<StorageProtection>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM storage_protections ORDER BY id ASC');
        if ($stmt === false) {
            return [];
        }

        return array_values(array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function findBySourceId(int $sourceLocationId): ?StorageProtection
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_protections WHERE source_location_id = ?');
        $stmt->execute([$sourceLocationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?StorageProtection
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_protections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every location that is somebody's destination, for the consumer that
     * refuses to let one be deleted out from under a source.
     *
     * @return list<int>
     */
    /**
     * The sources whose copy is written into $destinationLocationId.
     *
     * **Asked from the destination's side**, which is the direction the
     * relation is not stored in and the one a reconfiguration needs: the
     * screen is editing THIS location and has to find out whose files it
     * is holding before it publishes them.
     *
     * @return list<int>
     */
    public function sourceLocationIdsFor(int $destinationLocationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT source_location_id FROM storage_protections WHERE destination_location_id = ?'
        );
        $stmt->execute([$destinationLocationId]);

        return array_map(
            static fn(array $row): int => (int) $row['source_location_id'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return list<int>
     */
    public function destinationLocationIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT destination_location_id FROM storage_protections');
        if ($stmt === false) {
            return [];
        }

        return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * Creates or replaces the protection of one source.
     *
     * **« This source is protected, to here » is one fact**, and the
     * UNIQUE on the source says so: an administrator who changes the
     * destination is correcting a relation, not creating a second one.
     *
     * Read-then-write inside a transaction rather than
     * `INSERT … ON DUPLICATE KEY UPDATE`, which SQLite — the engine most
     * of this suite runs on — does not have. Two spellings of the same
     * statement is what `Help\Discovery\SeenTopicRepository` pays for
     * the same portability; here the branch is on a row this method has
     * to read anyway to answer with its id.
     *
     * **The working state is reset on every save**, deliberately: a pass
     * half-way through an inventory of the OLD destination has nothing to
     * say about the new one, and resuming into it would reconcile one
     * destination's inventory against another destination's files.
     */
    public function save(
        int $sourceLocationId,
        int $destinationLocationId,
        int $gracePeriodDays,
        int $cadenceHours,
        bool $enabled
    ): int {
        $this->pdo->beginTransaction();
        try {
            $existing = $this->findBySourceId($sourceLocationId);

            if ($existing !== null) {
                // **A new destination has never been copied to, so the
                // cadence must not remember the old one's last pass.**
                // `isDue()` gates on `last_completed_pass_at + cadence`,
                // so leaving it would let a destination that holds
                // nothing at all wait out a full cadence — up to a day by
                // default — right after an administrator acted to fix the
                // protection. Cleared only when the destination actually
                // changes: raising a grace period on the same destination
                // is not a reason to re-list a hundred thousand keys
                // tonight rather than tomorrow night.
                //
                // Written as two statements rather than one with a CASE
                // over a bound parameter: the engines do not agree on
                // whether a bound '1' equals the integer 1 — SQLite says
                // no, on storage class — and the clause silently never
                // fired.
                $clearsCadence = $existing->destinationLocationId !== $destinationLocationId
                    ? ', last_completed_pass_at = NULL'
                    : '';
                $stmt = $this->pdo->prepare(
                    'UPDATE storage_protections
                        SET destination_location_id = ?, enabled = ?, grace_period_days = ?, cadence_hours = ?,
                            pass_phase = NULL, pass_started_at = NULL, pass_cursor = NULL,
                            pass_page_last_key = NULL, pass_seen_count = 0, last_error = NULL' . $clearsCadence . '
                      WHERE id = ?'
                );
                $stmt->execute([
                    $destinationLocationId,
                    $enabled ? 1 : 0,
                    $gracePeriodDays,
                    $cadenceHours,
                    $existing->id,
                ]);
                $this->pdo->commit();

                return $existing->id;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO storage_protections
                    (source_location_id, destination_location_id, enabled, grace_period_days,
                     cadence_hours, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $sourceLocationId,
                $destinationLocationId,
                $enabled ? 1 : 0,
                $gracePeriodDays,
                $cadenceHours,
                date('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM storage_protections WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Records where a pass got to. Every column here is disposable (D12).
     *
     * **`$passStartedAt` is the run's own stamp, never a « now » computed
     * here**, and the difference is not cosmetic. The run that is pausing
     * has just written that exact string into `lastSeenAt` on every
     * inventory entry it met; the next run reads this column back and
     * compares its own stamp to those for equality
     * ({@see InventoryEntry::seenBy()}). This method used to write
     * `COALESCE(pass_started_at, date('Y-m-d H:i:s'))`, which on the first
     * pause of a pass stored a moment a whole time budget LATER than the
     * one the entries carry — so run 2 stamped with a value run 1's
     * entries could never match, and the sweep concluded that every file
     * run 1 had just seen had disappeared from the source. Written
     * unconditionally rather than coalesced: on a resume the value handed
     * back is the one already stored, so the write is a no-op, and when it
     * is not (a stored value that could not be parsed, so the run fell
     * back to its own clock) the run's stamp is the truthful one.
     */
    public function recordPassProgress(
        int $id,
        string $phase,
        ?string $cursor,
        ?string $pageLastKey,
        int $seenCount,
        string $passStartedAt
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_protections
                SET pass_phase = ?, pass_cursor = ?, pass_page_last_key = ?,
                    pass_seen_count = ?, pass_started_at = ?
              WHERE id = ?'
        );
        $stmt->execute([$phase, $cursor, $pageLastKey, $seenCount, $passStartedAt, $id]);
    }

    /**
     * A pass that finished: the working state goes, the date stays.
     */
    public function recordPassCompleted(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_protections
                SET pass_phase = NULL, pass_cursor = NULL, pass_page_last_key = NULL,
                    pass_seen_count = 0, pass_started_at = NULL,
                    last_completed_pass_at = ?, last_error = NULL
              WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
    }

    /**
     * A pass that gave up, with the reason an administrator will read.
     *
     * **The working state is cleared too**, and that is deliberate: a pass
     * that failed mid-inventory holds a cursor into a listing it can no
     * longer trust — expired credentials, a source that answered « empty »
     * — and resuming from it would carry the failure's own blindness into
     * the next run. D15 is the rule this serves: an inventory that did not
     * finish successfully never lets anything be marked as disappeared.
     */
    public function recordPassFailed(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_protections
                SET pass_phase = NULL, pass_cursor = NULL, pass_page_last_key = NULL,
                    pass_seen_count = 0, pass_started_at = NULL,
                    last_error = ?
              WHERE id = ?'
        );
        $stmt->execute([mb_substr($error, 0, 2000), $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StorageProtection
    {
        return new StorageProtection(
            id: (int) $row['id'],
            sourceLocationId: (int) $row['source_location_id'],
            destinationLocationId: (int) $row['destination_location_id'],
            enabled: (bool) $row['enabled'],
            gracePeriodDays: (int) $row['grace_period_days'],
            cadenceHours: (int) $row['cadence_hours'],
            passPhase: $row['pass_phase'] !== null ? (string) $row['pass_phase'] : null,
            passStartedAt: $row['pass_started_at'] !== null ? (string) $row['pass_started_at'] : null,
            passCursor: $row['pass_cursor'] !== null ? (string) $row['pass_cursor'] : null,
            passPageLastKey: ($row['pass_page_last_key'] ?? null) !== null
                ? (string) $row['pass_page_last_key']
                : null,
            passSeenCount: (int) ($row['pass_seen_count'] ?? 0),
            lastCompletedPassAt: $row['last_completed_pass_at'] !== null
                ? (string) $row['last_completed_pass_at']
                : null,
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            createdAt: $row['created_at'] !== null ? (string) $row['created_at'] : null
        );
    }
}
