<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * finance_transaction_attachments — the many-to-many join between
 * movements and receipts. Existence is checked before every insert
 * (rather than relying on INSERT IGNORE / ON DUPLICATE KEY, which aren't
 * portable between MySQL and the SQLite test database — same precedent
 * as TransactionRepository::insertOrSkip()).
 */
class TransactionAttachmentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function associate(int $transactionId, int $attachmentId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM finance_transaction_attachments WHERE transaction_id = ? AND attachment_id = ?');
        $stmt->execute([$transactionId, $attachmentId]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO finance_transaction_attachments (transaction_id, attachment_id) VALUES (?, ?)');
        $stmt->execute([$transactionId, $attachmentId]);
    }

    public function dissociate(int $transactionId, int $attachmentId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_transaction_attachments WHERE transaction_id = ? AND attachment_id = ?');
        $stmt->execute([$transactionId, $attachmentId]);
    }

    /**
     * @return int[]
     */
    public function findAttachmentIdsForTransaction(int $transactionId): array
    {
        $stmt = $this->pdo->prepare('SELECT attachment_id FROM finance_transaction_attachments WHERE transaction_id = ?');
        $stmt->execute([$transactionId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int[]
     */
    public function findTransactionIdsForAttachment(int $attachmentId): array
    {
        $stmt = $this->pdo->prepare('SELECT transaction_id FROM finance_transaction_attachments WHERE attachment_id = ?');
        $stmt->execute([$attachmentId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every attachment_id associated with at least one movement — used by
     * Service\ReceiptService::listPending() to find receipts with none.
     *
     * @return int[]
     */
    public function findAssociatedAttachmentIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT attachment_id FROM finance_transaction_attachments');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        return array_map('intval', $rows);
    }

    /**
     * Every transaction_id already linked to at least one receipt — used
     * by Service\ReceiptMatchingService to exclude movements that
     * already have a receipt from its candidate pool.
     *
     * @return int[]
     */
    public function findAssociatedTransactionIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT transaction_id FROM finance_transaction_attachments');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        return array_map('intval', $rows);
    }

    /**
     * Attachment counts per transaction, for the movements list's 📎
     * indicator — one query for every row on the page rather than N.
     *
     * @param int[] $transactionIds
     * @return array<int, int> transaction_id => attachment count
     */
    public function countByTransactionIds(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT transaction_id, COUNT(*) AS attachment_count FROM finance_transaction_attachments
             WHERE transaction_id IN ({$placeholders}) GROUP BY transaction_id"
        );
        $stmt->execute(array_values($transactionIds));

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['transaction_id']] = (int) $row['attachment_count'];
        }
        return $counts;
    }

    /**
     * Moves every association from $oldAttachmentId to $newAttachmentId
     * (Service\ReceiptService::replace()) — the old attachment keeps no
     * associations of its own once archived.
     */
    public function transferAttachment(int $oldAttachmentId, int $newAttachmentId): void
    {
        foreach ($this->findTransactionIdsForAttachment($oldAttachmentId) as $transactionId) {
            $this->associate($transactionId, $newAttachmentId);
        }
        $this->deleteAllForAttachment($oldAttachmentId);
    }

    public function deleteAllForAttachment(int $attachmentId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_transaction_attachments WHERE attachment_id = ?');
        $stmt->execute([$attachmentId]);
    }

    /**
     * Used by Task\PurgeOldMovementsHandler right before deleting a
     * purged transaction — the caller reads
     * findAttachmentIdsForTransaction() first to know which attachments
     * might now be orphaned.
     */
    public function deleteAllForTransaction(int $transactionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_transaction_attachments WHERE transaction_id = ?');
        $stmt->execute([$transactionId]);
    }
}
