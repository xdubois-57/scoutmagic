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

    /**
     * Bounded server-side, because the textarea's `maxlength` is a
     * convenience and never the limit: the write endpoint takes JSON, and
     * anything posted to it straight past the page is unbounded.
     *
     * The consequence of not doing it is worse than a long row. Past
     * ~65 508 plaintext bytes the ciphertext no longer fits
     * `comment_encrypted BLOB`, and the column then either refuses the
     * write or — under a non-strict server — truncates it silently, after
     * which `decrypt()` throws on EVERY later read of that row and takes
     * the sheet, the register, the animé's page and the export down with
     * it for the whole section.
     *
     * Same shape as `Modules\Groups\Service\PostService::MAX_BODY_LENGTH`.
     */
    public const MAX_COMMENT_LENGTH = 500;

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
     * Record one animé's state, touching nothing else on the row.
     *
     * **Only the `status` column is written.** A state and a comment are
     * two decisions, taken at different moments by possibly different
     * animateurs, and a write carrying both would silently replace the
     * comment this page loaded — which is the very lost update the sheet
     * exists to avoid when two people point the same list at once.
     */
    public function saveStatus(int $eventId, int $memberId, PresenceStatus $status, ?int $updatedBy): void
    {
        $this->upsert(
            'INSERT INTO presences_records
                (calendar_event_id, member_id, status, created_at, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            'status = excluded.status, updated_at = excluded.updated_at, updated_by = excluded.updated_by',
            'status = VALUES(status), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$eventId, $memberId, $status->value],
            $updatedBy
        );
        $this->deleteWhenNothingLeft($eventId, $memberId);
    }

    /**
     * Record what the staff wrote beside one animé, touching nothing else
     * on the row — see saveStatus() for why the two never travel
     * together.
     *
     * @param string|null $comment null or a blank string both mean
     *        « no comment », and both erase whatever was there
     */
    public function saveComment(int $eventId, int $memberId, ?string $comment, ?int $updatedBy): void
    {
        $comment = $comment !== null && trim($comment) !== ''
            ? mb_substr(trim($comment), 0, self::MAX_COMMENT_LENGTH)
            : null;
        $encrypted = $comment !== null ? $this->encryption->encrypt($comment, self::COMMENT_CONTEXT) : null;

        $this->upsert(
            'INSERT INTO presences_records
                (calendar_event_id, member_id, status, comment_encrypted, created_at, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'comment_encrypted = excluded.comment_encrypted,
             updated_at = excluded.updated_at, updated_by = excluded.updated_by',
            'comment_encrypted = VALUES(comment_encrypted),
             updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$eventId, $memberId, PresenceStatus::UNSET->value, $encrypted],
            $updatedBy
        );
        $this->deleteWhenNothingLeft($eventId, $memberId);
    }

    /**
     * The insert-or-update behind both writes, in ONE statement.
     *
     * A read-then-branch would lose the race the unique index then
     * reports as an error: two animateurs tapping the same animé for the
     * first time in the same second both find no row, both INSERT, and
     * the second one's tap comes back as a failure instead of being
     * recorded. The upsert has no such window.
     *
     * SQLite — the in-memory test database — spells it differently from
     * MySQL/MariaDB, hence the two clauses passed in; same portable
     * pairing as
     * `Modules\UsageStats\Repository\PageViewRepository::increment()`.
     * Both are private literals naming columns only; every value is
     * bound.
     *
     * The timestamps are written from PHP rather than by a column
     * default: the test database is SQLite, whose CURRENT_TIMESTAMP is
     * UTC while everything else here runs on Europe/Brussels
     * (docs/module-development.md § Timestamps).
     *
     * @param list<int|string|null> $values everything but the three timestamps
     */
    private function upsert(
        string $insert,
        string $sqliteAssignments,
        string $mysqlAssignments,
        array $values,
        ?int $updatedBy
    ): void {
        $now = AppClock::now()->format('Y-m-d H:i:s');

        $sql = $insert . ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ' ON CONFLICT(calendar_event_id, member_id) DO UPDATE SET ' . $sqliteAssignments
            : ' ON DUPLICATE KEY UPDATE ' . $mysqlAssignments);

        $this->pdo->prepare($sql)->execute([...$values, $now, $now, $updatedBy]);
    }

    /**
     * **A row carrying neither a state nor a comment is deleted, not kept
     * as 'unset'.** « Non renseigné » with nothing written beside it is
     * exactly what the absence of a row already means, and keeping one
     * would leave the table growing with decisions somebody took back.
     *
     * Expressed as a conditional DELETE rather than a read-then-decide so
     * that a comment saved between the two never disappears.
     */
    private function deleteWhenNothingLeft(int $eventId, int $memberId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM presences_records
              WHERE calendar_event_id = ? AND member_id = ?
                AND status = ? AND comment_encrypted IS NULL'
        );
        $stmt->execute([$eventId, $memberId, PresenceStatus::UNSET->value]);
    }

    /**
     * Every trace of one event, for when the evening itself is deleted —
     * see `Modules\Presences\Service\PresenceEventCleanupService`.
     */
    public function deleteByEvent(int $eventId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM presences_records WHERE calendar_event_id = ?');
        $stmt->execute([$eventId]);
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
     * **No screen calls this yet, and the RGPD page no longer claims one
     * does.** Two erasures ARE wired and cover the ordinary cases: the
     * member's record going takes the rows with it
     * (`fk_presences_member ... ON DELETE CASCADE`), and the evening being
     * deleted takes its sheet (`Api\PresenceEventCleanupInterface`). What
     * is missing is the PARTIAL one — the comments alone, states kept —
     * which is why this method exists and is tested: the erasure requests
     * §7 of the RGPD page describes are handled by the unit within a
     * month, and this is what that handling would call. Giving it a button
     * is worth its own change, not a corner of the module that introduced
     * it.
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
