<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * mass_mail_attachments — a plain join table between an email and a
 * core `files` row. Deliberately no encryption/EncryptedFileStorageService
 * here (module spec: a mass-communication attachment is admin-authored
 * content, not personal data) — files themselves are uploaded/stored via
 * Core\File\UploadHandler and gated by Core\File\FileAccessGuard using the
 * module.json `storage` entry's role_min, same as any other module.
 */
class EmailAttachmentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $emailId, int $fileId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_attachments (email_id, file_id) VALUES (?, ?)');
        $stmt->execute([$emailId, $fileId]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return EmailAttachment[]
     */
    public function findByEmailId(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_attachments WHERE email_id = ? ORDER BY id ASC');
        $stmt->execute([$emailId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?EmailAttachment
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_attachments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Only ever called while the parent email is still 'draft' — enforced
     * by Service\MassMailService, not here. Does not touch the underlying
     * `files` row/blob itself; the caller is responsible for that via
     * Core\File\UploadHandler.
     */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM mass_mail_attachments WHERE id = ?')->execute([$id]);
    }

    public function deleteByEmailId(int $emailId): void
    {
        $this->pdo->prepare('DELETE FROM mass_mail_attachments WHERE email_id = ?')->execute([$emailId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): EmailAttachment
    {
        return new EmailAttachment(
            id: (int) $row['id'],
            emailId: (int) $row['email_id'],
            fileId: (int) $row['file_id']
        );
    }
}
