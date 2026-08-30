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
use Modules\InboundMail\Api\MessageLink;

/**
 * The stored messages, their associations and their attachments.
 *
 * **A message and the objects it belongs to are two different things.** The
 * message is a row of `inbound_messages`; each association it carries is a
 * row of `inbound_message_links`. That is what lets one email be a
 * booking's correspondence and an invoice at the same time, and what makes
 * "detach" mean "remove one association" rather than "destroy the message".
 *
 * **Every read offered to a consumer is scoped**, either to a business
 * reference of its own or to a mailbox and a Message-ID. There is
 * deliberately no `findAll()` and no free-text search on this path: an
 * unscoped query is how a manager's access to one booking turns into a
 * window onto the unit's whole mailbox (§7.11), and the way to stop that is
 * not to write the query.
 *
 * Personal data — the subject, the addresses, both bodies, the attachment
 * names — is encrypted at rest and only ever decrypted here. What is
 * indexed instead is a blind index, since an encrypted column cannot be
 * compared.
 */
class InboundMessageRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Write the message itself. It belongs to nobody yet — `addLink()` is
     * what associates it with something.
     *
     * @param string[] $toEmails
     */
    public function create(
        int $mailboxId,
        string $folder,
        int $uidValidity,
        int $imapUid,
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
                (mailbox_id, folder, uid_validity, imap_uid,
                 message_id_blind_index, in_reply_to_blind_index, from_email_blind_index,
                 subject_encrypted, from_email_encrypted, from_name_encrypted, message_id_encrypted,
                 in_reply_to_encrypted, to_emails_encrypted, body_text_encrypted, body_html_encrypted, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $mailboxId,
            $folder,
            $uidValidity,
            $imapUid,
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

    // ── Associations ────────────────────────────────────────────────────

    /**
     * Associate a message with a business object, **idempotently**.
     *
     * Two people orienting the same message towards the same target produce
     * one association, not two, and neither of them sees an error: the
     * second is simply told nothing new happened. Towards two different
     * targets they produce two associations, which is a valid state rather
     * than a conflict.
     *
     * The SELECT-then-INSERT is deliberate rather than an
     * `INSERT ... ON DUPLICATE KEY`, which SQLite — the test database —
     * spells differently; the unique index is still what makes it correct
     * under a race, and that is what the catch below is for.
     *
     * @return bool whether an association was actually created
     */
    public function addLink(
        int $messageId,
        string $consumerId,
        string $businessReference,
        LinkOrigin $origin,
        int $attachmentId = 0,
        ?int $createdByUserAccountId = null
    ): bool {
        if ($this->hasLink($messageId, $consumerId, $businessReference, $attachmentId)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_message_links
                (message_id, consumer_id, business_reference, attachment_id, link_origin, created_by_user_account_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $messageId,
                $consumerId,
                $businessReference,
                $attachmentId,
                $origin->value,
                $createdByUserAccountId,
            ]);
        } catch (\PDOException) {
            // The unique index caught a concurrent insert of the same
            // association. Idempotent means idempotent: that is the state
            // the caller asked for, so it is not an error.
            return false;
        }

        return true;
    }

    public function hasLink(
        int $messageId,
        string $consumerId,
        string $businessReference,
        int $attachmentId = 0
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inbound_message_links
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ? AND attachment_id = ?
              LIMIT 1'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference, $attachmentId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Remove one consumer's association with one business object — every
     * attachment-level association included, since removing "this message
     * belongs to that booking" cannot sensibly leave "one of its files
     * belongs to that booking" behind.
     *
     * @return bool whether anything was removed
     */
    public function removeLink(int $messageId, string $consumerId, string $businessReference): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM inbound_message_links
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Every association a message carries, whoever made it.
     *
     * Unscoped on purpose, and the one read that is: this is what
     * `Service\InboundMessageAccessRegistry` asks before letting anybody
     * download an attachment, and it needs the whole list to know which
     * consumers to put the question to. Nothing about the message itself
     * comes back with it.
     *
     * @return MessageLink[]
     */
    public function findLinksForMessage(int $messageId): array
    {
        return $this->findLinksFor([$messageId])[$messageId] ?? [];
    }

    /**
     * How many associations a message still carries — what tells a caller
     * whether removing one has left the message belonging to nobody.
     */
    public function countLinks(int $messageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inbound_message_links WHERE message_id = ?');
        $stmt->execute([$messageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Move a consumer's association from one of its references to another.
     *
     * Idempotent in the same sense as `addLink()`: if the message is
     * already associated with the target, the source association is simply
     * removed rather than colliding with it.
     */
    public function moveToReference(
        int $messageId,
        string $consumerId,
        string $fromReference,
        string $toReference
    ): bool {
        if (!$this->hasLink($messageId, $consumerId, $fromReference)) {
            return false;
        }

        if ($this->hasLink($messageId, $consumerId, $toReference)) {
            $this->removeLink($messageId, $consumerId, $fromReference);

            return true;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE inbound_message_links SET business_reference = ?
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$toReference, $messageId, $consumerId, $fromReference]);

        return $stmt->rowCount() > 0;
    }

    // ── Reads scoped to one consumer ────────────────────────────────────

    /**
     * @return InboundMessage[]
     */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM inbound_messages m
               JOIN inbound_message_links l ON l.message_id = m.id
              WHERE l.consumer_id = ? AND l.business_reference = ?
           GROUP BY m.id
           ORDER BY m.sent_at ASC, m.id ASC'
        );
        $stmt->execute([$consumerId, $businessReference]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row) => (int) $row['id'], $rows);
        $attachments = $this->findAttachmentsFor($ids);
        $links = $this->findLinksFor($ids);

        return array_map(
            fn(array $row) => $this->hydrate(
                $row,
                $attachments[(int) $row['id']] ?? [],
                $links[(int) $row['id']] ?? [],
                $consumerId,
                $businessReference
            ),
            $rows
        );
    }

    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM inbound_messages m
               JOIN inbound_message_links l ON l.message_id = m.id
              WHERE m.id = ? AND l.consumer_id = ? AND l.business_reference = ?
              LIMIT 1'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate(
            $row,
            $this->findAttachmentsFor([$messageId])[$messageId] ?? [],
            $this->findLinksFor([$messageId])[$messageId] ?? [],
            $consumerId,
            $businessReference
        );
    }

    /**
     * The message ids associated with one business object.
     *
     * @return int[]
     */
    public function findMessageIdsForReference(string $consumerId, string $businessReference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT message_id FROM inbound_message_links
              WHERE consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$consumerId, $businessReference]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The message this mailbox already holds under that Message-ID, if any.
     *
     * **Per mailbox, not per business object.** A message exists once in a
     * box however many objects it ends up associated with — which is what
     * makes a UIDVALIDITY reset a re-read rather than a duplication (§7.5),
     * and what stopped the same email being stored twice because two
     * modules recognised it.
     */
    public function findIdByMessageId(int $mailboxId, string $messageId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_messages
              WHERE mailbox_id = ? AND message_id_blind_index = ?
              LIMIT 1'
        );
        $stmt->execute([$mailboxId, $this->messageIdIndex($messageId)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
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
                'SELECT l.business_reference, l.consumer_id
                   FROM inbound_messages m
                   JOIN inbound_message_links l ON l.message_id = m.id
                  WHERE m.mailbox_id = ? AND l.consumer_id = ? AND m.message_id_blind_index = ?
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

    // ── Deletion ────────────────────────────────────────────────────────

    /**
     * Destroy a message outright — its associations, its attachment rows,
     * then itself.
     *
     * Attachment rows go first, explicitly. The schema does declare
     * `ON DELETE CASCADE`, but relying on it for correctness would make
     * this method behave differently depending on the engine underneath —
     * and the caller reads `countAttachmentsForFile()` immediately
     * afterwards to decide whether a stored file is now unreferenced.
     * Deleting them here means that count is right on every engine, with
     * the cascade left as a backstop rather than as the mechanism.
     */
    public function deleteMessage(int $messageId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM inbound_messages WHERE id = ? LIMIT 1');
        $stmt->execute([$messageId]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM inbound_message_links WHERE message_id = ?');
        $stmt->execute([$messageId]);

        $stmt = $this->pdo->prepare('DELETE FROM inbound_message_attachments WHERE message_id = ?');
        $stmt->execute([$messageId]);

        $stmt = $this->pdo->prepare('DELETE FROM inbound_messages WHERE id = ?');
        $stmt->execute([$messageId]);

        return true;
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
     * The file already stored for these exact bytes **in this mailbox**, if
     * any — the same signature logo on ten messages is one file (§7.8).
     *
     * Per mailbox rather than per business object: now that the business
     * reference is no longer what a message is stored under, it is not what
     * a file can be deduplicated within either. The mailbox is the real
     * boundary — bytes never travel between two of the unit's boxes on
     * their own.
     */
    public function findFileIdByHash(int $mailboxId, string $contentHash): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.file_id
               FROM inbound_message_attachments a
               JOIN inbound_messages m ON a.message_id = m.id
              WHERE m.mailbox_id = ? AND a.content_hash = ?
              LIMIT 1'
        );
        $stmt->execute([$mailboxId, $contentHash]);
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
            'SELECT DISTINCT a.file_id
               FROM inbound_message_attachments a
               JOIN inbound_message_links l ON l.message_id = a.message_id
              WHERE l.consumer_id = ? AND l.business_reference = ?'
        );
        $stmt->execute([$consumerId, $businessReference]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * A message that still holds this file, other than the one named.
     *
     * Deduplication means several messages can share one stored file while
     * `files.owner_id` names only one of them. When that one is destroyed,
     * the survivors need the ownership handed over — otherwise the file
     * keeps pointing at a message that no longer exists, and
     * `Service\InboundMessageAccessRegistry` finds no associations to ask
     * about, silently locking out the very people who may read it.
     *
     * @return int|null the message id, or null when nothing holds it any more
     */
    public function findMessageHoldingFile(int $fileId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT message_id FROM inbound_message_attachments WHERE file_id = ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$fileId]);
        $messageId = $stmt->fetchColumn();

        return $messageId === false ? null : (int) $messageId;
    }

    /**
     * Every (file, message) pair an attachment row records, oldest first —
     * what the one-time reprise of `files.owner_type`/`owner_id` walks.
     *
     * @return array<int, array{file_id: int, message_id: int}>
     */
    public function findAttachmentFileOwners(): array
    {
        $stmt = $this->pdo->query(
            'SELECT file_id, message_id FROM inbound_message_attachments ORDER BY id ASC'
        );

        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn(array $row) => [
                'file_id' => (int) $row['file_id'],
                'message_id' => (int) $row['message_id'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
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

    // ── One-time reprise ────────────────────────────────────────────────

    /**
     * Turn every message's legacy `consumer_id`/`business_reference`/
     * `link_origin` triplet into a row of `inbound_message_links`.
     *
     * Runs once per installation, guarded by a setting in the composition
     * root — the `member_section_periods_backfilled` shape. Idempotent
     * regardless: `addLink()` refuses to create an association that already
     * exists, so a second run writes nothing and reports 0.
     *
     * Returns -1 when the legacy columns are already gone, which is both a
     * fresh installation and an installation that has passed the release
     * dropping them. Neither is an error and neither has anything to do.
     */
    public function backfillLinks(): int
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT id, consumer_id, business_reference, link_origin FROM inbound_messages
                  WHERE consumer_id IS NOT NULL AND consumer_id <> \'\''
            );
        } catch (\PDOException) {
            return -1;
        }

        if ($stmt === false) {
            return -1;
        }

        $created = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $origin = LinkOrigin::tryFrom((string) $row['link_origin']);
            if ($origin === null) {
                // An origin this build no longer knows. The association is
                // real and must survive; only the label for how it was
                // decided is lost, and 'sender' is the honest floor —
                // never presented as certain.
                $origin = LinkOrigin::SENDER;
            }

            if ($this->addLink(
                (int) $row['id'],
                (string) $row['consumer_id'],
                (string) $row['business_reference'],
                $origin
            )) {
                $created++;
            }
        }

        return $created;
    }

    // ── Hydration ───────────────────────────────────────────────────────

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
     * @param int[] $messageIds
     * @return array<int, MessageLink[]>
     */
    private function findLinksFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM inbound_message_links WHERE message_id IN ({$placeholders}) ORDER BY id ASC"
        );
        $stmt->execute($messageIds);

        $byMessage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byMessage[(int) $row['message_id']][] = new MessageLink(
                consumerId: (string) $row['consumer_id'],
                businessReference: (string) $row['business_reference'],
                origin: LinkOrigin::tryFrom((string) $row['link_origin']) ?? LinkOrigin::SENDER,
                attachmentId: (int) $row['attachment_id'],
                createdByUserAccountId: $row['created_by_user_account_id'] !== null
                    ? (int) $row['created_by_user_account_id']
                    : null,
                createdAt: DateInput::fromStorage((string) $row['created_at'])
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
     * @param MessageLink[] $links
     */
    private function hydrate(
        array $row,
        array $attachments,
        array $links,
        string $consumerId,
        string $businessReference
    ): InboundMessage {
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
            consumerId: $consumerId,
            businessReference: $businessReference,
            linkOrigin: $this->scopedOrigin($links, $consumerId, $businessReference),
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
            attachments: $attachments,
            links: $links
        );
    }

    /**
     * How the association this read was scoped to was decided. A message
     * carrying several says nothing about the others here — that is what
     * `$links` is for.
     *
     * @param MessageLink[] $links
     */
    private function scopedOrigin(array $links, string $consumerId, string $businessReference): LinkOrigin
    {
        foreach ($links as $link) {
            if ($link->consumerId === $consumerId && $link->businessReference === $businessReference) {
                return $link->origin;
            }
        }

        return LinkOrigin::SENDER;
    }
}
