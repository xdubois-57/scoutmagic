<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

class AttachmentRepository
{
    public function __construct(private \PDO $pdo)
    {
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
        $stmt = $this->pdo->query("SELECT * FROM finance_attachments WHERE status = 'active' ORDER BY uploaded_at DESC");
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
        ?string $suggestedSource = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_attachments
                (account_id, file_id, mime_type, original_filename, suggested_amount, suggested_date, suggested_source, parent_attachment_id, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId, $fileId, $mimeType, $originalFilename, $suggestedAmount, $suggestedDate, $suggestedSource, $parentAttachmentId, $uploadedBy,
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

    public function archiveAll(): int
    {
        $stmt = $this->pdo->exec("UPDATE finance_attachments SET status = 'archived' WHERE status = 'active'");
        return $stmt !== false ? $stmt : 0;
    }

    /**
     * @return Attachment[]
     */
    public function findActiveByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM finance_attachments WHERE status = 'active' AND account_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$accountId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
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
     * $suggestedSource distinguishes a manually-typed suggestion from one
     * written by Task\ExtractReceiptDataHandler (Attachment::
     * SUGGESTED_SOURCE_MANUAL / SUGGESTED_SOURCE_AI) — null clears it.
     */
    public function updateSuggestedData(int $id, ?float $suggestedAmount, ?string $suggestedDate, ?string $suggestedSource = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_attachments SET suggested_amount = ?, suggested_date = ?, suggested_source = ? WHERE id = ?');
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
        $stmt->execute([$suggestedLabel, $id]);
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
            suggestedLabel: $row['suggested_label'] !== null ? (string) $row['suggested_label'] : null,
            suggestedSource: $row['suggested_source'] !== null ? (string) $row['suggested_source'] : null,
            status: (string) $row['status'],
            parentAttachmentId: $row['parent_attachment_id'] !== null ? (int) $row['parent_attachment_id'] : null,
            uploadedBy: $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
            uploadedAt: (string) $row['uploaded_at'],
            matchingAiAttemptedAt: $row['matching_ai_attempted_at'] !== null ? (string) $row['matching_ai_attempted_at'] : null
        );
    }
}
