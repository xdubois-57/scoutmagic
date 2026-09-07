<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Repository;

use Core\Config\AppClock;
use Core\Security\EncryptionService;
use Modules\Presences\Value\PresenceStatus;

/**
 * Every read and write of `presences_records`, and the only place a
 * comment is ever encrypted or decrypted (SECURITY.md §5).
 *
 * Two shapes recur and are worth naming once. A **sheet** is one event and
 * all its rows, keyed by member id — that is what the pointing screen
 * needs. A **register** is many events and many members, keyed
 * `[eventId][memberId]` — that is what the section's yearly page and the
 * export need, and it is read in ONE statement however many evenings the
 * year holds, never one query per date.
 */
class PresenceRepository
{
    /**
     * The AES-GCM additional-authenticated-data context, which binds a
     * ciphertext to the column it came from: a comment blob moved into
     * another encrypted column fails to decrypt instead of quietly
     * meaning something else.
     */
    private const COMMENT_CONTEXT = 'presences_records.comment';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * One event's recorded decisions, keyed by member id. Members with no
     * row are simply absent from the result — « non renseigné » is the
     * absence of a row, and inventing one here would make every caller
     * carry the same emptiness check twice.
     *
     * @return array<int, PresenceRecord>
     */
    public function findByEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM presences_records WHERE calendar_event_id = ?');
        $stmt->execute([$eventId]);

        $records = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $record = $this->hydrate($row);
            $records[$record->memberId] = $record;
        }

        return $records;
    }

    /**
     * Many events at once, keyed `[eventId][memberId]`. One statement: a
     * section's year is twenty-odd evenings and the page that draws them
     * must not cost twenty round trips.
     *
     * @param list<int> $eventIds
     * @return array<int, array<int, PresenceRecord>>
     */
    public function findByEvents(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM presences_records WHERE calendar_event_id IN ({$placeholders})"
        );
        $stmt->execute($eventIds);

        $byEvent = array_fill_keys($eventIds, []);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $record = $this->hydrate($row);
            $byEvent[$record->calendarEventId][$record->memberId] = $record;
        }

        return $byEvent;
    }

    /**
     * One animé's decisions across a set of events, keyed by event id —
     * the animé's own page and their line of the export.
     *
     * @param list<int> $eventIds
     * @return array<int, PresenceRecord>
     */
    public function findByMemberAndEvents(int $memberId, array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM presences_records
             WHERE member_id = ? AND calendar_event_id IN ({$placeholders})"
        );
        $stmt->execute([$memberId, ...$eventIds]);

        $records = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $record = $this->hydrate($row);
            $records[$record->calendarEventId] = $record;
        }

        return $records;
    }

    public function find(int $eventId, int $memberId): ?PresenceRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM presences_records WHERE calendar_event_id = ? AND member_id = ?'
        );
        $stmt->execute([$eventId, $memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Record one decision, comment included — an upsert on
     * (calendar_event_id, member_id), which is what makes a second tap on
     * the same animé a correction rather than a second row.
     *
     * **A row carrying neither a state nor a comment is deleted, not
     * stored as 'unset'.** « Non renseigné » with nothing written beside
     * it is exactly what the absence of a row already means, and keeping
     * one would leave the table growing with decisions somebody took back.
     *
     * Written with an explicit PHP timestamp rather than a column default:
     * the test database is SQLite, whose CURRENT_TIMESTAMP is UTC while
     * everything else here runs on Europe/Brussels
     * (docs/module-development.md § Timestamps).
     *
     * @param string|null $comment null or '' both mean « no comment »
     */
    public function save(
        int $eventId,
        int $memberId,
        PresenceStatus $status,
        ?string $comment,
        ?int $updatedBy
    ): void {
        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        if ($status === PresenceStatus::UNSET && $comment === null) {
            $this->delete($eventId, $memberId);
            return;
        }

        $now = AppClock::now()->format('Y-m-d H:i:s');
        $encrypted = $comment !== null ? $this->encryption->encrypt($comment, self::COMMENT_CONTEXT) : null;

        $existing = $this->find($eventId, $memberId);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE presences_records
                 SET status = ?, comment_encrypted = ?, updated_at = ?, updated_by = ?
                 WHERE id = ?'
            );
            $stmt->execute([$status->value, $encrypted, $now, $updatedBy, $existing->id]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO presences_records
                (calendar_event_id, member_id, status, comment_encrypted, created_at, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$eventId, $memberId, $status->value, $encrypted, $now, $now, $updatedBy]);
    }

    public function delete(int $eventId, int $memberId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM presences_records WHERE calendar_event_id = ? AND member_id = ?'
        );
        $stmt->execute([$eventId, $memberId]);
    }

    /**
     * How many animés were recorded in each state, per event — the sheet
     * counters of a whole register in one aggregate statement, without
     * decrypting a single comment.
     *
     * The result is sparse on purpose: a state nobody recorded has no key,
     * and an event nobody opened has an empty array. The caller already
     * knows how many animés the section has, which is the only way to
     * count « non renseigné » correctly anyway (it is a subtraction, not
     * a stored value).
     *
     * @param list<int> $eventIds
     * @return array<int, array<string, int>> [eventId][status] => count
     */
    public function countByStatusForEvents(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT calendar_event_id, status, COUNT(*) AS total
             FROM presences_records
             WHERE calendar_event_id IN ({$placeholders})
             GROUP BY calendar_event_id, status"
        );
        $stmt->execute($eventIds);

        $counts = array_fill_keys($eventIds, []);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['calendar_event_id']][(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Erase the comments of the given animés without touching the states.
     *
     * A right-to-erasure request takes away what somebody wrote about a
     * person; it does not take away that the person was there, which is
     * neither personal narrative nor the staff's opinion. Same distinction
     * `Core\Audit\AuditService::anonymiseValues()` draws.
     *
     * @param list<int> $memberIds
     */
    public function eraseComments(array $memberIds): int
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE presences_records SET comment_encrypted = NULL
             WHERE member_id IN ({$placeholders}) AND comment_encrypted IS NOT NULL"
        );
        $stmt->execute($memberIds);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PresenceRecord
    {
        $encrypted = $row['comment_encrypted'] ?? null;
        // A resource comes back from PDO for a BLOB on some drivers;
        // stream_get_contents() is what the rest of this codebase does.
        if (is_resource($encrypted)) {
            $encrypted = stream_get_contents($encrypted);
        }

        $comment = null;
        if (is_string($encrypted) && $encrypted !== '') {
            $comment = $this->encryption->decrypt($encrypted, self::COMMENT_CONTEXT);
            if ($comment === '') {
                $comment = null;
            }
        }

        return new PresenceRecord(
            id: (int) $row['id'],
            calendarEventId: (int) $row['calendar_event_id'],
            memberId: (int) $row['member_id'],
            status: PresenceStatus::tryParse((string) $row['status']) ?? PresenceStatus::UNSET,
            comment: $comment,
            updatedAt: (string) $row['updated_at']
        );
    }
}
