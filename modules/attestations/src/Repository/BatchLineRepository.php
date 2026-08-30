<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Repository;

use Core\Config\AppClock;
use Core\Database\Connection;
use Core\Security\EncryptionService;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\DeliveryState;
use Modules\Attestations\Value\MatchState;

/**
 * `attestation_batch_lines` and its candidate rows.
 *
 * The one place this module encrypts or decrypts a printed name
 * (SECURITY.md §5). Everything else it stores is an identifier, a page
 * number or a count.
 */
class BatchLineRepository
{
    public const ENCRYPTION_CONTEXT = 'attestation_batch_lines.read_name';

    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param list<int> $candidateMemberIds the members an ambiguous line
     *                                      could belong to; stored as rows
     *                                      so the server can later check a
     *                                      submitted answer against them
     */
    public function create(
        int $batchId,
        int $position,
        int $firstPage,
        int $lastPage,
        ?string $readName,
        ?int $memberId,
        MatchState $state,
        int $fileId,
        array $candidateMemberIds = []
    ): int {
        $stmt = $this->connection->getPdo()->prepare(
            'INSERT INTO attestation_batch_lines
                 (batch_id, position, first_page, last_page, read_name_encrypted,
                  member_id, state, file_id, is_selected, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $batchId,
            $position,
            $firstPage,
            $lastPage,
            $readName === null ? null : $this->encryption->encrypt($readName, self::ENCRYPTION_CONTEXT),
            $memberId,
            $state->value,
            $fileId,
            // A line with no member cannot be selected: it has no
            // destination, so offering it as "will be distributed" would be
            // a promise the validation could not keep.
            $memberId !== null ? 1 : 0,
            AppClock::now()->format('Y-m-d H:i:s'),
        ]);

        $lineId = (int) $this->connection->getPdo()->lastInsertId();

        foreach ($candidateMemberIds as $candidateId) {
            $this->addCandidate($lineId, $candidateId);
        }

        return $lineId;
    }

    public function addCandidate(int $lineId, int $memberId): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'INSERT INTO attestation_line_candidates (line_id, member_id) VALUES (?, ?)'
        );
        $stmt->execute([$lineId, $memberId]);
    }

    /**
     * Every line of a batch, in the order the pages came in.
     *
     * @return list<BatchLine>
     */
    public function findByBatch(int $batchId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT * FROM attestation_batch_lines WHERE batch_id = ? ORDER BY position ASC'
        );
        $stmt->execute([$batchId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $candidates = $this->candidatesByLine(array_map(
            static fn(array $row): int => (int) $row['id'],
            $rows
        ));

        return array_map(
            fn(array $row): BatchLine => $this->mapRow($row, $candidates[(int) $row['id']] ?? []),
            $rows
        );
    }

    public function findById(int $id): ?BatchLine
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT * FROM attestation_batch_lines WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapRow($row, $this->candidatesByLine([(int) $row['id']])[(int) $row['id']] ?? []);
    }

    /**
     * Resolve a line onto one member, and drop its candidates — a resolved
     * line has a member, not a shortlist.
     *
     * The state becomes `matched` whichever state it was in: what the
     * column records from here on is that this line has a destination.
     */
    public function assignMember(int $lineId, int $memberId): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'UPDATE attestation_batch_lines SET member_id = ?, state = ?, is_selected = 1 WHERE id = ?'
        );
        $stmt->execute([$memberId, MatchState::Matched->value, $lineId]);

        $stmt = $this->connection->getPdo()->prepare(
            'DELETE FROM attestation_line_candidates WHERE line_id = ?'
        );
        $stmt->execute([$lineId]);
    }

    /**
     * True when this member is one of the line's recorded candidates.
     *
     * A member id arriving in a request body is a request, never an
     * authority (SECURITY.md §3): resolving an ambiguity onto somebody the
     * reader was never offered is exactly the wrong-family outcome the
     * ambiguous state exists to prevent.
     */
    public function isCandidate(int $lineId, int $memberId): bool
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM attestation_line_candidates WHERE line_id = ? AND member_id = ?'
        );
        $stmt->execute([$lineId, $memberId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Record the reader's ticks. Lines with no member are forced off
     * regardless of what the form said — the browser decides whether a box
     * looks tickable, the server decides what a batch holds.
     *
     * @param list<int> $selectedLineIds
     */
    public function applySelection(int $batchId, array $selectedLineIds): void
    {
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare('UPDATE attestation_batch_lines SET is_selected = 0 WHERE batch_id = ?');
        $stmt->execute([$batchId]);

        if ($selectedLineIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($selectedLineIds), '?'));
        $stmt = $pdo->prepare(
            'UPDATE attestation_batch_lines
             SET is_selected = 1
             WHERE batch_id = ? AND member_id IS NOT NULL AND id IN (' . $placeholders . ')'
        );
        $stmt->execute([$batchId, ...$selectedLineIds]);
    }

    /**
     * Delete the lines nobody kept, and hand back the file ids so the
     * caller can delete the bytes too. Row first, bytes second
     * (Core\File\AttachedFileRemover's rule, ARCHITECTURE.md §8.3): an
     * interruption between the two leaves a stored file nothing points at,
     * which is invisible and recoverable, rather than a row pointing at
     * bytes that are gone.
     *
     * @return list<int> the file ids of the deleted lines
     */
    public function deleteUnselected(int $batchId): array
    {
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare(
            'SELECT id, file_id FROM attestation_batch_lines WHERE batch_id = ? AND is_selected = 0'
        );
        $stmt->execute([$batchId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $stmt = $pdo->prepare('DELETE FROM attestation_batch_lines WHERE batch_id = ? AND is_selected = 0');
        $stmt->execute([$batchId]);

        return array_map(static fn(array $row): int => (int) $row['file_id'], $rows);
    }

    /**
     * Record the document publication put on the member's page. This is
     * what makes the batch reversible: it names exactly the rows THIS batch
     * created.
     */
    public function attachDocument(int $lineId, int $memberDocumentId): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'UPDATE attestation_batch_lines SET member_document_id = ? WHERE id = ?'
        );
        $stmt->execute([$memberDocumentId, $lineId]);
    }

    /**
     * The lines of a published batch nothing has been attempted for yet,
     * oldest first and bounded.
     *
     * Bounded because distribution is a mail send per member: a batch of two
     * hundred is two hundred SMTP round trips, which is why it runs through
     * the scheduler in slices rather than inside the request that asked for
     * it (deliverability, and a page that would otherwise time out).
     *
     * @return list<BatchLine>
     */
    public function findPendingDelivery(int $batchId, int $limit): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT * FROM attestation_batch_lines
             WHERE batch_id = ? AND member_id IS NOT NULL AND delivery_state = ?
             ORDER BY position ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$batchId, DeliveryState::Pending->value]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row): BatchLine => $this->mapRow($row, []), $rows);
    }

    public function recordDelivery(int $lineId, DeliveryState $state, ?string $sentAt): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'UPDATE attestation_batch_lines SET delivery_state = ?, sent_at = ? WHERE id = ?'
        );
        $stmt->execute([$state->value, $sentAt, $lineId]);
    }

    /**
     * How many lines are in each delivery state — what the published
     * screen reports, and what tells the handler it has finished.
     *
     * @return array<string, int> state value => count
     */
    public function countByDeliveryState(int $batchId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT delivery_state, COUNT(*) AS total
             FROM attestation_batch_lines
             WHERE batch_id = ?
             GROUP BY delivery_state'
        );
        $stmt->execute([$batchId]);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['delivery_state']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Every member a published batch reached, once each — the audience of
     * the one notification the batch sends.
     *
     * @return list<int>
     */
    public function findMemberIds(int $batchId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT member_id FROM attestation_batch_lines
             WHERE batch_id = ? AND member_id IS NOT NULL
             ORDER BY member_id ASC'
        );
        $stmt->execute([$batchId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function countByBatch(int $batchId): int
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT COUNT(*) FROM attestation_batch_lines WHERE batch_id = ?'
        );
        $stmt->execute([$batchId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many lines still have no member. Nothing is published while one
     * remains.
     */
    public function countUnresolved(int $batchId): int
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT COUNT(*) FROM attestation_batch_lines WHERE batch_id = ? AND member_id IS NULL'
        );
        $stmt->execute([$batchId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Which of these members already have a certificate of the same
     * category, for the same scout year, in an ALREADY PUBLISHED batch.
     *
     * This is the only thing the category is for (Value\AttestationCategory):
     * reconciling batches with each other. Matching on the label instead
     * would confuse a tax certificate with an attendance certificate — two
     * perfectly legitimate documents for the same person in the same season.
     *
     * Draft batches are excluded on purpose: a batch nobody has validated
     * has given nobody anything, and warning about it would make a reader
     * untick a line for a document that does not exist.
     *
     * @param list<int> $memberIds
     * @return array<int, list<array{label: string, published_at: string|null}>>
     *         keyed by members.id, most recently published first
     */
    public function findPublishedOccurrences(
        array $memberIds,
        string $category,
        int $scoutYearId,
        int $excludeBatchId
    ): array {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($memberIds), '?'));
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT l.member_id, b.label, b.published_at
             FROM attestation_batch_lines l
             JOIN attestation_batches b ON b.id = l.batch_id
             WHERE l.member_id IN (' . $placeholders . ')
               AND b.category = ?
               AND b.scout_year_id = ?
               AND b.status = ?
               AND b.id <> ?
             ORDER BY b.published_at DESC, b.id DESC'
        );
        $stmt->execute([
            ...$memberIds,
            $category,
            $scoutYearId,
            BatchStatus::Published->value,
            $excludeBatchId,
        ]);

        $occurrences = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $occurrences[(int) $row['member_id']][] = [
                'label' => (string) $row['label'],
                'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
            ];
        }

        return $occurrences;
    }

    /**
     * Every member who already holds a published certificate of this
     * category for this scout year.
     *
     * **Keyed on `members.id`, never on the annual row**, which is the
     * whole point of the coverage screen: somebody who has left the unit
     * received a certificate for the season they were there, and matching
     * on `member_years.id` would count them as missing every year
     * afterwards.
     *
     * Published only, for `findPublishedOccurrences()`'s reason: a batch
     * nobody validated has given nobody anything, so counting it would
     * report a coverage the families do not have.
     *
     * @return list<int> members.id, ascending
     */
    public function findCoveredMemberIds(string $category, int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT l.member_id
             FROM attestation_batch_lines l
             JOIN attestation_batches b ON b.id = l.batch_id
             WHERE l.member_id IS NOT NULL
               AND b.category = ?
               AND b.scout_year_id = ?
               AND b.status = ?
             ORDER BY l.member_id ASC'
        );
        $stmt->execute([$category, $scoutYearId, BatchStatus::Published->value]);

        return array_map(
            static fn(array $row): int => (int) $row['member_id'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * The `member_documents` rows a batch's lines produced — what taking
     * the batch back has to delete, and nothing else.
     *
     * @return list<int> member_documents.id
     */
    public function findMemberDocumentIds(int $batchId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT member_document_id FROM attestation_batch_lines
             WHERE batch_id = ? AND member_document_id IS NOT NULL'
        );
        $stmt->execute([$batchId]);

        return array_map(
            static fn(array $row): int => (int) $row['member_document_id'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Every stored certificate of a batch, published or not — the bytes a
     * reset has to remove once its rows are gone.
     *
     * @return list<int> files.id
     */
    public function findFileIds(int $batchId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT file_id FROM attestation_batch_lines WHERE batch_id = ?'
        );
        $stmt->execute([$batchId]);

        return array_map(
            static fn(array $row): int => (int) $row['file_id'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function deleteByBatch(int $batchId): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare(
            'DELETE FROM attestation_line_candidates
             WHERE line_id IN (SELECT id FROM attestation_batch_lines WHERE batch_id = ?)'
        );
        $stmt->execute([$batchId]);

        $stmt = $pdo->prepare('DELETE FROM attestation_batch_lines WHERE batch_id = ?');
        $stmt->execute([$batchId]);
    }

    /**
     * @param list<int> $lineIds
     * @return array<int, list<int>> line id => member ids
     */
    private function candidatesByLine(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($lineIds), '?'));
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT line_id, member_id FROM attestation_line_candidates
             WHERE line_id IN (' . $placeholders . ')
             ORDER BY line_id ASC, member_id ASC'
        );
        $stmt->execute($lineIds);

        $candidates = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $candidates[(int) $row['line_id']][] = (int) $row['member_id'];
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $candidateMemberIds
     */
    private function mapRow(array $row, array $candidateMemberIds): BatchLine
    {
        return new BatchLine(
            id: (int) $row['id'],
            batchId: (int) $row['batch_id'],
            position: (int) $row['position'],
            firstPage: (int) $row['first_page'],
            lastPage: (int) $row['last_page'],
            readName: $this->decrypt($row['read_name_encrypted']),
            memberId: $row['member_id'] !== null ? (int) $row['member_id'] : null,
            // A stored value naming no known state would be a row this code
            // never wrote; reading it as unmatched keeps the line visible
            // and undistributable, which is the safe direction.
            state: MatchState::tryFrom((string) $row['state']) ?? MatchState::Unmatched,
            fileId: (int) $row['file_id'],
            isSelected: (bool) $row['is_selected'],
            candidateMemberIds: $candidateMemberIds,
            memberDocumentId: ($row['member_document_id'] ?? null) !== null
                ? (int) $row['member_document_id']
                : null,
            deliveryState: DeliveryState::tryFromValue(
                isset($row['delivery_state']) ? (string) $row['delivery_state'] : null
            ) ?? DeliveryState::Pending,
            sentAt: ($row['sent_at'] ?? null) !== null ? (string) $row['sent_at'] : null
        );
    }

    private function decrypt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $plain = $this->encryption->decrypt((string) $value, self::ENCRYPTION_CONTEXT);
        } catch (\Throwable) {
            return null;
        }

        return $plain === '' ? null : $plain;
    }
}
