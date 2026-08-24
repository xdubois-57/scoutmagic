<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Repository;

use Core\Security\EncryptionService;
use Modules\TestTools\Service\CapturedEmail;

/**
 * The only place a captured recipient is ever encrypted or decrypted
 * (AGENTS.md § Database). Everything above this class handles addresses in
 * clear or not at all.
 *
 * Same convention as Core\Member\MemberEmailRepository: every timestamp is
 * computed in PHP and bound as a parameter, never MySQL's NOW(), so this
 * repository and its tests run unmodified against the SQLite test database.
 */
class CapturedEmailRepository
{
    private const ENCRYPTION_PURPOSE = 'captured_emails.recipient';
    private const BLIND_INDEX_PURPOSE = 'email';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param array<int, array{file_name: string, mime_type: string, size_bytes: int, file_id: int|null}> $attachments
     */
    public function create(
        \DateTimeImmutable $capturedAt,
        string $subject,
        string $recipient,
        string $fromAddress,
        ?string $replyTo,
        int $sizeBytes,
        bool $hasDkim,
        ?int $mimeFileId,
        ?int $bodyHtmlFileId,
        ?int $bodyTextFileId,
        ?string $errorMessage,
        array $attachments
    ): int {
        $normalizedRecipient = EncryptionService::normalizeEmailForIndex($recipient);

        $stmt = $this->pdo->prepare(
            'INSERT INTO captured_emails
                (captured_at, subject, recipient, recipient_blind_index, from_address, reply_to,
                 size_bytes, has_dkim, attachment_count, mime_file_id,
                 body_html_file_id, body_text_file_id, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $capturedAt->format('Y-m-d H:i:s'),
            mb_substr($subject, 0, 255),
            $this->encryption->encrypt($normalizedRecipient, self::ENCRYPTION_PURPOSE),
            $this->encryption->blindIndex($normalizedRecipient, self::BLIND_INDEX_PURPOSE),
            mb_substr($fromAddress, 0, 255),
            $replyTo !== null ? mb_substr($replyTo, 0, 255) : null,
            $sizeBytes,
            $hasDkim ? 1 : 0,
            count($attachments),
            $mimeFileId,
            $bodyHtmlFileId,
            $bodyTextFileId,
            $errorMessage,
        ]);

        $capturedEmailId = (int) $this->pdo->lastInsertId();

        $attachmentStmt = $this->pdo->prepare(
            'INSERT INTO captured_email_attachments
                (captured_email_id, file_name, mime_type, size_bytes, file_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($attachments as $attachment) {
            $attachmentStmt->execute([
                $capturedEmailId,
                mb_substr($attachment['file_name'], 0, 255),
                mb_substr($attachment['mime_type'], 0, 150),
                $attachment['size_bytes'],
                $attachment['file_id'],
            ]);
        }

        return $capturedEmailId;
    }

    public function findById(int $id): ?CapturedEmail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM captured_emails WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row, $this->findAttachments($id));
    }

    /**
     * The newest captured messages first, optionally narrowed by a subject
     * fragment and/or an exact recipient address.
     *
     * The subject is a plain LIKE (it is stored in clear); the recipient is
     * matched on its blind index, which is the only way an encrypted column
     * can be searched for an exact value. $ids narrows to an already-known
     * set — the bounded body scan's result (MailSandboxService).
     *
     * @param array<int, int>|null $ids
     * @return array<int, CapturedEmail>
     */
    public function findPage(
        int $limit,
        int $offset,
        ?string $subjectFragment = null,
        ?string $recipient = null,
        ?array $ids = null
    ): array {
        [$where, $bindings] = $this->buildFilter($subjectFragment, $recipient, $ids);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM captured_emails{$where} ORDER BY captured_at DESC, id DESC LIMIT ? OFFSET ?"
        );
        foreach ($bindings as $i => $binding) {
            $stmt->bindValue($i + 1, $binding, \PDO::PARAM_STR);
        }
        $stmt->bindValue(count($bindings) + 1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(count($bindings) + 2, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $attachmentsByEmail = $this->findAttachmentsFor(
            array_map(static fn(array $row): int => (int) $row['id'], $rows)
        );

        return array_map(
            fn(array $row): CapturedEmail => $this->hydrate($row, $attachmentsByEmail[(int) $row['id']] ?? []),
            $rows
        );
    }

    /**
     * @param array<int, int>|null $ids
     */
    public function countAll(?string $subjectFragment = null, ?string $recipient = null, ?array $ids = null): int
    {
        [$where, $bindings] = $this->buildFilter($subjectFragment, $recipient, $ids);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM captured_emails{$where}");
        $stmt->execute($bindings);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The ids to drop when retention bites, oldest first — never more than
     * $limit at a time so a purge run stays bounded.
     *
     * @return array<int, int>
     */
    public function findIdsBeyond(int $keep, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM captured_emails ORDER BY captured_at ASC, id ASC LIMIT ? OFFSET ?'
        );
        $total = $this->countAll();
        $excess = max(0, $total - $keep);
        if ($excess === 0) {
            return [];
        }
        $stmt->bindValue(1, min($excess, $limit), \PDO::PARAM_INT);
        $stmt->bindValue(2, 0, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn(mixed $id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every files.id a captured message references — the raw MIME message
     * and each attachment — so a caller can delete the files and the rows
     * together and leave neither orphaned.
     *
     * @return array<int, int>
     */
    public function findFileIds(int $capturedEmailId): array
    {
        $fileIds = [];

        $stmt = $this->pdo->prepare(
            'SELECT mime_file_id, body_html_file_id, body_text_file_id FROM captured_emails WHERE id = ?'
        );
        $stmt->execute([$capturedEmailId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            foreach (['mime_file_id', 'body_html_file_id', 'body_text_file_id'] as $column) {
                if ($row[$column] !== null) {
                    $fileIds[] = (int) $row[$column];
                }
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT file_id FROM captured_email_attachments WHERE captured_email_id = ? AND file_id IS NOT NULL'
        );
        $stmt->execute([$capturedEmailId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $fileId) {
            $fileIds[] = (int) $fileId;
        }

        return $fileIds;
    }

    /**
     * Deletes the metadata rows only. The caller is responsible for the
     * encrypted files (findFileIds() above) — deliberately two steps, so
     * neither can be forgotten silently inside the other.
     */
    public function delete(int $capturedEmailId): void
    {
        // ON DELETE CASCADE covers the attachments on MySQL; SQLite needs
        // foreign keys switched on to honour it, and the test database does
        // not switch them on, so delete them explicitly rather than relying
        // on a behaviour that differs between the two.
        $stmt = $this->pdo->prepare('DELETE FROM captured_email_attachments WHERE captured_email_id = ?');
        $stmt->execute([$capturedEmailId]);

        $stmt = $this->pdo->prepare('DELETE FROM captured_emails WHERE id = ?');
        $stmt->execute([$capturedEmailId]);
    }

    /**
     * The newest $limit ids — the bounded candidate set for the decrypted
     * body scan (MailSandboxService::searchBodies()).
     *
     * @return array<int, int>
     */
    public function findRecentIds(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM captured_emails ORDER BY captured_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn(mixed $id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, int> every captured message's id, oldest first
     */
    public function findAllIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM captured_emails ORDER BY captured_at ASC, id ASC');
        if ($stmt === false) {
            return [];
        }

        return array_map(static fn(mixed $id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The WHERE clause for the list and its count, plus the values to bind.
     *
     * Every condition is a string LITERAL carrying a `?` where a value
     * goes; nothing a caller typed ever reaches the SQL text. The values
     * travel back in the second element and are bound by execute() —
     * same shape as Modules\MassMail\Repository\EmailRepository and
     * Modules\Finance\Repository\AttachmentRepository, and
     * Tests\Security\SqlInjectionAuditTest reviews `{$where}` on that
     * basis.
     *
     * @param array<int, int>|null $ids
     * @return array{0: string, 1: array<int, string>}
     */
    private function buildFilter(?string $subjectFragment, ?string $recipient, ?array $ids = null): array
    {
        $conditions = [];
        $bindings = [];

        if ($ids !== null) {
            if ($ids === []) {
                // An empty result set, expressed as a condition nothing
                // satisfies rather than as a caller-side special case.
                return [' WHERE 1 = 0', []];
            }
            $conditions[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $bindings[] = (string) $id;
            }
        }

        if ($subjectFragment !== null && trim($subjectFragment) !== '') {
            $conditions[] = 'subject LIKE ?';
            $bindings[] = '%' . trim($subjectFragment) . '%';
        }

        if ($recipient !== null && trim($recipient) !== '') {
            $conditions[] = 'recipient_blind_index = ?';
            $bindings[] = $this->encryption->blindIndex(
                EncryptionService::normalizeEmailForIndex($recipient),
                self::BLIND_INDEX_PURPOSE
            );
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    /**
     * @return array<int, array{id: int, file_name: string, mime_type: string, size_bytes: int, file_id: int|null}>
     */
    private function findAttachments(int $capturedEmailId): array
    {
        return $this->findAttachmentsFor([$capturedEmailId])[$capturedEmailId] ?? [];
    }

    /**
     * @param array<int, int> $capturedEmailIds
     * @return array<int, array<int, array{id: int, file_name: string, mime_type: string, size_bytes: int, file_id: int|null}>>
     */
    private function findAttachmentsFor(array $capturedEmailIds): array
    {
        if ($capturedEmailIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($capturedEmailIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM captured_email_attachments WHERE captured_email_id IN (' . $placeholders . ') ORDER BY id ASC'
        );
        $stmt->execute($capturedEmailIds);

        $byEmail = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byEmail[(int) $row['captured_email_id']][] = [
                'id' => (int) $row['id'],
                'file_name' => (string) $row['file_name'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'file_id' => $row['file_id'] !== null ? (int) $row['file_id'] : null,
            ];
        }

        return $byEmail;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array{id: int, file_name: string, mime_type: string, size_bytes: int, file_id: int|null}> $attachments
     */
    private function hydrate(array $row, array $attachments): CapturedEmail
    {
        return new CapturedEmail(
            id: (int) $row['id'],
            capturedAt: new \DateTimeImmutable((string) $row['captured_at']),
            subject: (string) $row['subject'],
            recipient: $this->encryption->decrypt((string) $row['recipient'], self::ENCRYPTION_PURPOSE),
            fromAddress: (string) $row['from_address'],
            replyTo: $row['reply_to'] !== null ? (string) $row['reply_to'] : null,
            sizeBytes: (int) $row['size_bytes'],
            hasDkim: (bool) $row['has_dkim'],
            attachmentCount: (int) $row['attachment_count'],
            mimeFileId: $row['mime_file_id'] !== null ? (int) $row['mime_file_id'] : null,
            bodyHtmlFileId: $row['body_html_file_id'] !== null ? (int) $row['body_html_file_id'] : null,
            bodyTextFileId: $row['body_text_file_id'] !== null ? (int) $row['body_text_file_id'] : null,
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            attachments: $attachments
        );
    }
}
