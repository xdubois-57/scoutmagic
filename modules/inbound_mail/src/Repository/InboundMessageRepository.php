<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;

/**
 * The claimed messages and their attachments.
 *
 * **Every read is scoped**, either to a business reference or to a mailbox
 * and a Message-ID. There is deliberately no `findAll()` and no free-text
 * search: an unscoped query is how a manager's access to one booking turns
 * into a window onto the unit's whole mailbox (§7.11), and the way to stop
 * that is not to write the query.
 *
 * Personal data — the subject, the addresses, both bodies, the attachment
 * names — is encrypted at rest and only ever decrypted here. What is
 * indexed instead is a blind index, since an encrypted column cannot be
 * compared for equality.
 */
class InboundMessageRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param string[] $toEmails
     */
    public function create(
        int $mailboxId,
        string $folder,
        int $uidValidity,
        int $imapUid,
        string $consumerId,
        string $businessReference,
        LinkOrigin $linkOrigin,
        string $messageId,
        ?string $inReplyTo,
        string $subject,
        string $fromEmail,
        ?string $fromName,
        string $bodyText,
        string $bodyHtml,
        \DateTimeImmutable $sentAt,
        array $toEmails = []
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_messages
                (mailbox_id, folder, uid_validity, imap_uid, consumer_id, business_reference, link_origin,
                 message_id_blind_index, in_reply_to_blind_index, from_email_blind_index,
                 subject_encrypted, from_email_encrypted, from_name_encrypted, message_id_encrypted,
                 in_reply_to_encrypted, to_emails_encrypted, body_text_encrypted, body_html_encrypted, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $mailboxId,
            $folder,
            $uidValidity,
            $imapUid,
            $consumerId,
            $businessReference,
            $linkOrigin->value,
            $this->messageIdIndex($messageId),
            $inReplyTo !== null ? $this->messageIdIndex($inReplyTo) : null,
            $this->encryption->blindIndex(EncryptionService::normalizeEmailForIndex($fromEmail), 'email'),
            $this->encryption->encrypt($subject, 'inbound_messages.subject'),
            $this->encryption->encrypt($fromEmail, 'inbound_messages.from_email'),
            $fromName !== null ? $this->encryption->encrypt($fromName, 'inbound_messages.from_name') : null,
            $this->encryption->encrypt($messageId, 'inbound_messages.message_id'),
            $inReplyTo !== null ? $this->encryption->encrypt($inReplyTo, 'inbound_messages.in_reply_to') : null,
            $toEmails !== []
                ? $this->encryption->encrypt(implode("\n", $toEmails), 'inbound_messages.to_emails')
                : null,
            $this->encryption->encrypt($bodyText, 'inbound_messages.body_text'),
            $this->encryption->encrypt($bodyHtml, 'inbound_messages.body_html'),
            $sentAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return InboundMessage[]
     */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inbound_messages
              WHERE consumer_id = ? AND business_reference = ?
           ORDER BY sent_at ASC, id ASC'
        );
        $stmt->execute([$consumerId, $businessReference]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $attachments = $this->findAttachmentsFor(array_map(static fn(array $row) => (int) $row['id'], $rows));

        return array_map(
            fn(array $row) => $this->hydrate($row, $attachments[(int) $row['id']] ?? []),
            $rows
        );
    }

    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inbound_messages WHERE id = ? AND consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row, $this->findAttachmentsFor([$messageId])[$messageId] ?? []);
    }

    /**
     * Whether this mailbox already holds this message for this object —
     * the deduplication that survives a UIDVALIDITY change, since it looks
     * at the Message-ID rather than the UID (§7.5).
     */
    public function existsForReference(
        int $mailboxId,
        string $consumerId,
        string $businessReference,
        string $messageId
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inbound_messages
              WHERE mailbox_id = ? AND consumer_id = ? AND business_reference = ? AND message_id_blind_index = ?
              LIMIT 1'
        );
        $stmt->execute([$mailboxId, $consumerId, $businessReference, $this->messageIdIndex($messageId)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The business object a known message belongs to, looked up by one of
     * the Message-IDs a reply names. This is what makes a reply carrying no
     * reference land on the right object anyway (§7.6, level 2) — and it
     * lives here rather than in a consumer because the consumer has no way
     * to look inside this module's storage.
     *
     * @param string[] $messageIds most specific first
     * @return array{reference: string, consumer_id: string}|null
     */
    public function findReferenceByThread(int $mailboxId, string $consumerId, array $messageIds): ?array
    {
        foreach ($messageIds as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $stmt = $this->pdo->prepare(
                'SELECT business_reference, consumer_id FROM inbound_messages
                  WHERE mailbox_id = ? AND consumer_id = ? AND message_id_blind_index = ?
                  LIMIT 1'
            );
            $stmt->execute([$mailboxId, $consumerId, $this->messageIdIndex($candidate)]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (is_array($row)) {
                return [
                    'reference' => (string) $row['business_reference'],
                    'consumer_id' => (string) $row['consumer_id'],
                ];
            }
        }

        return null;
    }

    public function moveToReference(int $messageId, string $consumerId, string $fromReference, string $toReference): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_messages SET business_reference = ?
              WHERE id = ? AND consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$toReference, $messageId, $consumerId, $fromReference]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Attachment rows go first, explicitly.
     *
     * The schema does declare `ON DELETE CASCADE`, but relying on it for
     * correctness would make this method behave differently depending on
     * the engine underneath — and the caller reads
     * `countAttachmentsForFile()` immediately afterwards to decide whether
     * a stored file is now unreferenced. Deleting them here means that
     * count is right on every engine, with the cascade left as a backstop
     * rather than as the mechanism.
     */
    public function delete(int $messageId, string $consumerId, string $businessReference): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inbound_messages WHERE id = ? AND consumer_id = ? AND business_reference = ? LIMIT 1'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM inbound_message_attachments WHERE message_id = ?');
        $stmt->execute([$messageId]);

        $stmt = $this->pdo->prepare('DELETE FROM inbound_messages WHERE id = ?');
        $stmt->execute([$messageId]);

        return true;
    }

    public function deleteForReference(string $consumerId, string $businessReference): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_messages WHERE consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$consumerId, $businessReference]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM inbound_message_attachments WHERE message_id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $stmt = $this->pdo->prepare("DELETE FROM inbound_messages WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        return count($ids);
    }

    // ── Attachments ─────────────────────────────────────────────────────

    public function addAttachment(
        int $messageId,
        int $fileId,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contentHash
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_message_attachments
                (message_id, file_id, filename_encrypted, mime_type, size_bytes, content_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $messageId,
            $fileId,
            $this->encryption->encrypt($filename, 'inbound_message_attachments.filename'),
            $mimeType,
            $sizeBytes,
            $contentHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The file already stored for these exact bytes on this business
     * object, if any — the same signature logo on ten messages is one file
     * (§7.8).
     */
    public function findFileIdByHash(string $consumerId, string $businessReference, string $contentHash): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.file_id
               FROM inbound_message_attachments a
               JOIN inbound_messages m ON a.message_id = m.id
              WHERE m.consumer_id = ? AND m.business_reference = ? AND a.content_hash = ?
              LIMIT 1'
        );
        $stmt->execute([$consumerId, $businessReference, $contentHash]);
        $fileId = $stmt->fetchColumn();

        return $fileId === false ? null : (int) $fileId;
    }

    /**
     * The file ids a message's attachments point at, so a caller deleting
     * the message can clean up the files nothing else references.
     *
     * @return int[]
     */
    public function findFileIdsForMessage(int $messageId): array
    {
        $stmt = $this->pdo->prepare('SELECT file_id FROM inbound_message_attachments WHERE message_id = ?');
        $stmt->execute([$messageId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int[]
     */
    public function findFileIdsForReference(string $consumerId, string $businessReference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.file_id
               FROM inbound_message_attachments a
               JOIN inbound_messages m ON a.message_id = m.id
              WHERE m.consumer_id = ? AND m.business_reference = ?'
        );
        $stmt->execute([$consumerId, $businessReference]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * How many stored attachments still point at a file — what tells a
     * caller whether deleting a message may also delete the file, or
     * whether another message deduplicated onto it.
     */
    public function countAttachmentsForFile(int $fileId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inbound_message_attachments WHERE file_id = ?');
        $stmt->execute([$fileId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param int[] $messageIds
     * @return array<int, InboundAttachment[]>
     */
    private function findAttachmentsFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM inbound_message_attachments WHERE message_id IN ({$placeholders}) ORDER BY id ASC"
        );
        $stmt->execute($messageIds);

        $byMessage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byMessage[(int) $row['message_id']][] = new InboundAttachment(
                id: (int) $row['id'],
                messageId: (int) $row['message_id'],
                fileId: (int) $row['file_id'],
                filename: $this->encryption->decrypt(
                    (string) $row['filename_encrypted'],
                    'inbound_message_attachments.filename'
                ),
                mimeType: (string) $row['mime_type'],
                sizeBytes: (int) $row['size_bytes'],
                contentHash: (string) $row['content_hash']
            );
        }

        return $byMessage;
    }

    /**
     * A Message-ID is not personal data the way an address is, but it can
     * carry one — plenty of servers build it from the sender's local part —
     * so it is indexed through the same keyed blind index as everything
     * else rather than a bare hash.
     */
    private function messageIdIndex(string $messageId): string
    {
        return $this->encryption->blindIndex(strtolower(trim($messageId, "<> \t")), 'inbound_message_id');
    }

    /**
     * @param array<string, mixed> $row
     * @param InboundAttachment[] $attachments
     */
    private function hydrate(array $row, array $attachments): InboundMessage
    {
        $toEmails = [];
        if ($row['to_emails_encrypted'] !== null) {
            $toEmails = array_values(array_filter(explode(
                "\n",
                $this->encryption->decrypt((string) $row['to_emails_encrypted'], 'inbound_messages.to_emails')
            ), static fn(string $email) => $email !== ''));
        }

        return new InboundMessage(
            id: (int) $row['id'],
            mailboxId: (int) $row['mailbox_id'],
            consumerId: (string) $row['consumer_id'],
            businessReference: (string) $row['business_reference'],
            linkOrigin: LinkOrigin::from((string) $row['link_origin']),
            subject: $this->encryption->decrypt((string) $row['subject_encrypted'], 'inbound_messages.subject'),
            fromEmail: $this->encryption->decrypt((string) $row['from_email_encrypted'], 'inbound_messages.from_email'),
            fromName: $row['from_name_encrypted'] !== null
                ? $this->encryption->decrypt((string) $row['from_name_encrypted'], 'inbound_messages.from_name')
                : null,
            messageId: $this->encryption->decrypt((string) $row['message_id_encrypted'], 'inbound_messages.message_id'),
            inReplyTo: $row['in_reply_to_encrypted'] !== null
                ? $this->encryption->decrypt((string) $row['in_reply_to_encrypted'], 'inbound_messages.in_reply_to')
                : null,
            sentAt: DateInput::requireFromStorage((string) $row['sent_at'], 'sent_at'),
            bodyText: $this->encryption->decrypt((string) $row['body_text_encrypted'], 'inbound_messages.body_text'),
            bodyHtml: $this->encryption->decrypt((string) $row['body_html_encrypted'], 'inbound_messages.body_html'),
            toEmails: $toEmails,
            attachments: $attachments
        );
    }
}
