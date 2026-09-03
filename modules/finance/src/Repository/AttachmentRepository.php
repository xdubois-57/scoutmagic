<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

use Core\Security\EncryptionService;

class AttachmentRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * A live receipt holding exactly these bytes, on this account (or on
     * the sorting pile when $accountId is null) — the one that stops the
     * same invoice being filed twice.
     */
    public function findActiveByContentHash(?int $accountId, string $contentHash): ?Attachment
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM finance_attachments
              WHERE content_hash = ? AND status = ?
                AND ((? IS NULL AND account_id IS NULL) OR account_id = ?)
              ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$contentHash, Attachment::STATUS_ACTIVE, $accountId, $accountId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->findById((int) $id);
    }

    public function findById(int $id): ?Attachment
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_attachments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Attachment[]
     */
    public function findActiveOrdered(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM finance_attachments WHERE status = 'active' ORDER BY uploaded_at "
            . "DESC");
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function create(
        ?int $accountId,
        int $fileId,
        string $mimeType,
        string $originalFilename,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        ?int $parentAttachmentId,
        ?int $uploadedBy,
        ?string $suggestedSource = null,
        ?string $contentHash = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_attachments
                (account_id, file_id, mime_type, original_filename, suggested_amount, suggested_date, suggested_source, parent_attachment_id, uploaded_by, content_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId, $fileId, $mimeType, $originalFilename, $suggestedAmount, $suggestedDate,
            $suggestedSource, $parentAttachmentId, $uploadedBy, $contentHash,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * User-facing deletion (Service\ReceiptService::delete()) never
     * physically removes an attachment — only archives it, so proof of
     * an expense is never lost by accident.
     */
    public function archive(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE finance_attachments SET status = 'archived' WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * The one genuine physical deletion in this module — used only by
     * Task\PurgeOldMovementsHandler once an attachment has zero
     * remaining movement associations after a fiscal year is purged.
     * Callers must delete the underlying encrypted file themselves first
     * (Core\File\EncryptedFileStorageService::delete()).
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_attachments WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * The config page's "zone de danger" bulk archive. Drops every movement
     * association first, exactly as the per-receipt path
     * (Service\ReceiptService::delete()) does — archiving alone used to
     * leave the finance_transaction_attachments rows behind, so movements
     * kept showing a paperclip count and the archived receipt's merchant/
     * description via Service\MovementPresenter, and — the durable part —
     * Service\ReceiptMatchingService::candidateTransactions() went on
     * excluding those movements as "already has a receipt" forever, so no
     * new receipt could ever be auto-matched to them again.
     */
    public function archiveAll(): int
    {
        $this->pdo->exec(
            "DELETE FROM finance_transaction_attachments
             WHERE attachment_id IN (SELECT id FROM finance_attachments WHERE status = 'active')"
        );

        $stmt = $this->pdo->exec("UPDATE finance_attachments SET status = 'archived' WHERE status = 'active'");
        return $stmt !== false ? $stmt : 0;
    }

    /**
     * @return Attachment[]
     */
    public function findActiveByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM finance_attachments WHERE status = 'active' AND account_id = ? "
            . "ORDER BY uploaded_at DESC");
        $stmt->execute([$accountId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Backs the dashboard's "Reçus" metric box — a plain COUNT(*), never
     * findActiveByAccountId()'s full decrypt-every-field hydration, since
     * only the number is needed here.
     */
    public function countActiveByAccountId(int $accountId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM finance_attachments WHERE status = 'active' AND account_id "
            . "= ?");
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Stamps `files.owner_type`/`owner_id` on every receipt file that has
     * no owner yet, so a receipt uploaded before
     * File\FinanceAccountOwnershipChecker existed follows the same rule as
     * one uploaded after it. Without this the iteration would close the
     * hole only for new receipts and leave every existing one reachable by
     * a direct /files/{id} — which is the whole hole.
     *
     * **Set-based on purpose**, unlike the per-file loop
     * Controller\ConfigAccountController::syncReceiptFilesRoleMin() uses:
     * that one walks one account's receipts on an admin action, this one
     * walks every receipt the installation has ever stored, exactly once,
     * during somebody's page load. Thousands of round trips there would be
     * a timeout, not a backfill.
     *
     * Two statements because they say two different things. The first
     * gives a receipt its account. The second covers a receipt whose
     * `account_id` is NULL — a legacy row, or one filed straight from an
     * email nothing could attribute (Service\ReceiptService::
     * uploadUnattributed()). `owner_id` 0 is the unit's sorting pile:
     * File\FinanceAccountOwnershipChecker::UNASSIGNED_OWNER_ID, ruled on
     * by Service\AccountVisibility::isUnassignedReceiptVisibleTo() rather
     * than by an account that does not exist.
     *
     * Never touches a file that already carries an owner: re-running is a
     * no-op, and a file owned by something else is none of this module's
     * business.
     */
    public function backfillFileOwnership(string $ownerType): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE files
                SET owner_type = ?,
                    owner_id = (SELECT fa.account_id FROM finance_attachments fa WHERE fa.file_id = files.id LIMIT 1)
              WHERE owner_type IS NULL
                AND id IN (SELECT file_id FROM finance_attachments WHERE account_id IS NOT NULL)'
        );
        $stmt->execute([$ownerType]);

        $stmt = $this->pdo->prepare(
            'UPDATE files
                SET owner_type = ?, owner_id = 0
              WHERE owner_type IS NULL
                AND id IN (SELECT file_id FROM finance_attachments WHERE account_id IS NULL)'
        );
        $stmt->execute([$ownerType]);
    }

    /**
     * The unit's sorting pile: active receipts no account claims.
     *
     * A plain COUNT(*), like countActiveByAccountId() — the receipts
     * page's account picker only needs the number beside « Compte
     * inconnu », and hydrating every row to count them would decrypt
     * several fields per receipt to render one integer.
     */
    public function countActiveUnassigned(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM finance_attachments WHERE status = 'active' AND account_id IS NULL"
        );

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Move one receipt to another account — or to the sorting pile, with
     * a null.
     *
     * **Three writes, one transaction, and the order inside it does not
     * matter because of that.** They cannot be allowed to happen apart:
     *
     *  - `account_id` is what every screen reads;
     *  - `files.owner_id`/`role_min` are what `/files/{id}` reads, and a
     *    row that moved while its file did not is a receipt the OLD
     *    account's people can still download (§8.70);
     *  - the movement associations have to go, because
     *    Service\ReceiptService::associate() guarantees a receipt and its
     *    movements share an account, and moving the receipt is exactly
     *    what would break that guarantee.
     *
     * Here rather than in the service because this is where the PDO is:
     * the same reason Service\ImportService holds one for its own
     * transaction. The caller resolves what the new owner and floor should
     * be — this method does not know what an account is.
     *
     * @return int the number of movement associations that were dropped
     */
    public function reassign(
        int $attachmentId,
        ?int $accountId,
        int $fileId,
        string $ownerType,
        int $ownerId,
        string $roleMin
    ): int
    {
        $this->pdo->beginTransaction();

        try {
            $dropped = $this->pdo->prepare('DELETE FROM finance_transaction_attachments WHERE attachment_id = ?');
            $dropped->execute([$attachmentId]);
            $droppedCount = $dropped->rowCount();

            $stmt = $this->pdo->prepare('UPDATE finance_attachments SET account_id = ? WHERE id = ?');
            $stmt->execute([$accountId, $attachmentId]);

            $stmt = $this->pdo->prepare('UPDATE files SET owner_type = ?, owner_id = ?, role_min = ? WHERE id = ?');
            $stmt->execute([$ownerType, $ownerId, $roleMin, $fileId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $droppedCount;
    }

    /**
     * Every file_id (active or archived) belonging to an account — used
     * by Controller\ConfigAccountController to keep a receipt's
     * underlying file's role_min in sync whenever the account's own
     * role_min_view changes.
     *
     * @return int[]
     */
    public function findFileIdsForAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT file_id FROM finance_attachments WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $ids
     * @return Attachment[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM finance_attachments WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($ids));
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Active receipts for one account, optionally restricted to still-
     * pending ones (no movement association at all) and/or a free-text
     * search across every receipt-related text field — the single query
     * backing Controller\ReceiptController::list()/search() (the page's
     * filter bar and its "N reçus par page" pagination both read from
     * this, never from a client-side slice of the full set, per the
     * "filter is global, not per page" requirement). movement_count is
     * computed in the same query (a LEFT JOIN + GROUP BY) rather than
     * one extra query per row.
     *
     * suggested_label/suggested_description are encrypted (non-
     * deterministic ciphertext), so a search term can never be matched
     * against them with a SQL WHERE/LIKE clause — when $search is given,
     * every account/pending-matching row is fetched, decrypted, and
     * matched in PHP instead (same approach as TransactionRepository::
     * findFiltered() for the same reason), and pagination is applied to
     * that filtered PHP array rather than via SQL LIMIT/OFFSET.
     *
     * @return array<int, array{attachment: Attachment, movement_count: int}>
     */
    public function findFilteredForAccount(
        ?int $accountId,
        bool $pendingOnly,
        ?string $search,
        int $limit,
        int $offset
    ): array
    {
        $search = $search !== null ? trim($search) : null;

        if ($search === null || $search === '') {
            [$fromWhere, $params] = $this->buildFilterSql($accountId, $pendingOnly);
            $sql = "SELECT fa.*, COUNT(fta.transaction_id) AS movement_count {$fromWhere} ORDER BY fa.uploaded_at "
                . "DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $results = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $results[] = ['attachment' => $this->hydrate($row), 'movement_count' => (int) $row['movement_count']];
            }
            return $results;
        }

        return array_slice($this->findAllMatchingSearch($accountId, $pendingOnly, $search), $offset, $limit);
    }

    /**
     * Total number of receipts matching the same filter as
     * findFilteredForAccount() — the page-count half of that method's
     * pagination, kept as a separate query since COUNT(*) over a
     * GROUP BY/HAVING needs to be wrapped in a subquery anyway.
     */
    public function countFilteredForAccount(?int $accountId, bool $pendingOnly, ?string $search): int
    {
        $search = $search !== null ? trim($search) : null;

        if ($search === null || $search === '') {
            [$fromWhere, $params] = $this->buildFilterSql($accountId, $pendingOnly);
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM (SELECT fa.id {$fromWhere}) AS matched");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        }

        return count($this->findAllMatchingSearch($accountId, $pendingOnly, $search));
    }

    /**
     * @return array<int, array{attachment: Attachment, movement_count: int}>
     */
    private function findAllMatchingSearch(?int $accountId, bool $pendingOnly, string $search): array
    {
        [$fromWhere, $params] = $this->buildFilterSql($accountId, $pendingOnly);
        $stmt = $this->pdo->prepare("SELECT fa.*, COUNT(fta.transaction_id) AS movement_count {$fromWhere} ORDER BY "
            . "fa.uploaded_at DESC");
        $stmt->execute($params);

        $results = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $attachment = $this->hydrate($row);
            if ($this->matchesSearch($attachment, $search)) {
                $results[] = ['attachment' => $attachment, 'movement_count' => (int) $row['movement_count']];
            }
        }
        return $results;
    }

    private function matchesSearch(Attachment $attachment, string $search): bool
    {
        if (mb_stripos($attachment->originalFilename, $search) !== false) {
            return true;
        }
        if ($attachment->suggestedDate !== null && mb_stripos($attachment->suggestedDate, $search) !== false) {
            return true;
        }
        if ($attachment->suggestedAmount !== null && str_contains(
            number_format($attachment->suggestedAmount, 2, '.', ''),
            $search
        )) {
            return true;
        }
        if ($attachment->suggestedLabel !== null && mb_stripos($attachment->suggestedLabel, $search) !== false) {
            return true;
        }
        if ($attachment->suggestedDescription !== null && mb_stripos(
            $attachment->suggestedDescription,
            $search
        ) !== false) {
            return true;
        }
        return false;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildFilterSql(?int $accountId, bool $pendingOnly): array
    {
        // Null is the sorting pile — every receipt no account claims. It
        // needs `IS NULL` rather than a parameter: `account_id = NULL` is
        // never true in SQL, so the same query with a null bound would
        // silently return an empty page instead of the pile.
        $accountCondition = $accountId === null ? 'fa.account_id IS NULL' : 'fa.account_id = ?';

        $sql = " FROM finance_attachments fa
                 LEFT JOIN finance_transaction_attachments fta ON fta.attachment_id = fa.id
                 WHERE fa.status = 'active' AND {$accountCondition}
                 GROUP BY fa.id";
        $params = $accountId === null ? [] : [$accountId];

        if ($pendingOnly) {
            $sql .= ' HAVING COUNT(fta.transaction_id) = 0';
        }

        return [$sql, $params];
    }

    /**
     * $suggestedSource distinguishes a manually-typed suggestion from one
     * written by Task\ExtractReceiptDataHandler (Attachment::
     * SUGGESTED_SOURCE_MANUAL / SUGGESTED_SOURCE_AI) — null clears it.
     */
    public function updateSuggestedData(
        int $id,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        ?string $suggestedSource = null
    ): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_attachments SET suggested_amount = ?, suggested_date = ?, '
            . 'suggested_source = ? WHERE id = ?');
        $stmt->execute([$suggestedAmount, $suggestedDate, $suggestedSource, $id]);
    }

    /**
     * Written only by Task\ExtractReceiptDataHandler — kept separate from
     * updateSuggestedData() so a manual amount/date edit (Controller\
     * ReceiptController::update(), which never touches the label) can
     * never accidentally wipe out an AI-derived merchant/reason.
     */
    public function updateSuggestedLabel(int $id, string $suggestedLabel): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_attachments SET suggested_label = ? WHERE id = ?');
        $stmt->execute([$this->encryption->encrypt($suggestedLabel, 'finance_attachments.suggested_label'), $id]);
    }

    /**
     * Written only by Task\ExtractReceiptDataHandler, same as
     * updateSuggestedLabel() — a one-sentence AI-generated summary of
     * what the receipt is for.
     */
    public function updateSuggestedDescription(int $id, string $suggestedDescription): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_attachments SET suggested_description = ? WHERE id = ?');
        $stmt->execute([$this->encryption->encrypt($suggestedDescription, 'finance_attachments.suggested_description'),
            $id]);
    }

    /**
     * Marks that Service\ReceiptMatchingService has spent this receipt's
     * one allowed AI-assisted matching attempt — set regardless of
     * whether that attempt found a movement, so it is never retried.
     */
    public function markAiMatchAttempted(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_attachments SET matching_ai_attempted_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Attachment
    {
        return new Attachment(
            id: (int) $row['id'],
            accountId: $row['account_id'] !== null ? (int) $row['account_id'] : null,
            fileId: (int) $row['file_id'],
            mimeType: (string) $row['mime_type'],
            originalFilename: (string) $row['original_filename'],
            suggestedAmount: $row['suggested_amount'] !== null ? (float) $row['suggested_amount'] : null,
            suggestedDate: $row['suggested_date'] !== null ? (string) $row['suggested_date'] : null,
            suggestedLabel: $row['suggested_label'] !== null ? $this->decryptOrLegacyPlaintext(
                (string) $row['suggested_label'],
                'finance_attachments.suggested_label'
            ) : null,
            suggestedSource: $row['suggested_source'] !== null ? (string) $row['suggested_source'] : null,
            status: (string) $row['status'],
            parentAttachmentId: $row['parent_attachment_id'] !== null ? (int) $row['parent_attachment_id'] : null,
            uploadedBy: $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
            uploadedAt: (string) $row['uploaded_at'],
            matchingAiAttemptedAt: $row['matching_ai_attempted_at'] !== null
                ? (string) $row['matching_ai_attempted_at']
                : null,
            suggestedDescription: $row['suggested_description'] !== null ? $this->decryptOrLegacyPlaintext(
                (string) $row['suggested_description'],
                'finance_attachments.suggested_description'
            ) : null
        );
    }

    /**
     * suggested_label/suggested_description were plain VARCHAR columns
     * before this module started encrypting them — an existing row from
     * before that change holds real plaintext, which is not valid
     * ciphertext and would otherwise throw DecryptionException on every
     * read. Falls back to the raw stored value in that case rather than
     * ever crashing a page over it; a one-time re-encryption pass
     * (documented alongside the migration, not code the app runs itself)
     * converts these at rest so this fallback becomes a dead path in the
     * steady state.
     */
    private function decryptOrLegacyPlaintext(string $value, string $context): string
    {
        try {
            return $this->encryption->decrypt($value, $context);
        } catch (\Core\Security\DecryptionException) {
            return $value;
        }
    }
}
