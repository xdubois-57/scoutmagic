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
        ?int $uploadedBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_attachments
                (account_id, file_id, mime_type, original_filename, suggested_amount, suggested_date, parent_attachment_id, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId, $fileId, $mimeType, $originalFilename, $suggestedAmount, $suggestedDate, $parentAttachmentId, $uploadedBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Attachments are never physically deleted — only archived (see
     * schema.sql's comment on finance_attachments).
     */
    public function archive(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE finance_attachments SET status = 'archived' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function archiveAll(): int
    {
        $stmt = $this->pdo->exec("UPDATE finance_attachments SET status = 'archived' WHERE status = 'active'");
        return $stmt !== false ? $stmt : 0;
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
            status: (string) $row['status'],
            parentAttachmentId: $row['parent_attachment_id'] !== null ? (int) $row['parent_attachment_id'] : null,
            uploadedBy: $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
            uploadedAt: (string) $row['uploaded_at']
        );
    }
}
